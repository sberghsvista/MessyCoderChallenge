<?php
session_start();

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin123';

function h($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function identifier(string $name): string
{
	return '`' . str_replace('`', '``', $name) . '`';
}

function dbConnection()
{
	require_once __DIR__ . '/database.inc.php';
	foreach (get_defined_vars() as $value) {
		if ($value instanceof PDO || (class_exists('mysqli', false) && $value instanceof mysqli)) {
			return $value;
		}
	}
	foreach (['pdo', 'conn', 'mysqli', 'db', 'link', 'connection'] as $name) {
		if (isset($GLOBALS[$name]) && ($GLOBALS[$name] instanceof PDO || (class_exists('mysqli', false) && $GLOBALS[$name] instanceof mysqli))) {
			return $GLOBALS[$name];
		}
	}
	throw new RuntimeException('No PDO or mysqli connection was found in database.inc.php.');
}

function bindMysqli($statement, array &$values): void
{
	if (!$values) {
		return;
	}
	$types = '';
	$refs = [];
	foreach ($values as $key => &$value) {
		$types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
		$refs[$key] = &$value;
	}
	$statement->bind_param($types, ...$refs);
}

function runQuery($db, string $sql, array $params = []): array
{
	if ($db instanceof PDO) {
		$statement = $db->prepare($sql);
		$statement->execute($params);
		return $statement->fetchAll(PDO::FETCH_ASSOC);
	}
	$statement = $db->prepare($sql);
	if (!$statement) {
		throw new RuntimeException($db->error);
	}
	bindMysqli($statement, $params);
	if (!$statement->execute()) {
		throw new RuntimeException($statement->error);
	}
	$result = $statement->get_result();
	$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
	$statement->close();
	return $rows;
}

function runChange($db, string $sql, array $params = []): void
{
	if ($db instanceof PDO) {
		$statement = $db->prepare($sql);
		$statement->execute($params);
		return;
	}
	$statement = $db->prepare($sql);
	if (!$statement) {
		throw new RuntimeException($db->error);
	}
	bindMysqli($statement, $params);
	if (!$statement->execute()) {
		throw new RuntimeException($statement->error);
	}
	$statement->close();
}

if (empty($_SESSION['admin_logged_in'])) {
	$loginError = '';
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
		if (hash_equals(ADMIN_USER, (string) ($_POST['username'] ?? '')) && hash_equals(ADMIN_PASS, (string) ($_POST['password'] ?? ''))) {
			session_regenerate_id(true);
			$_SESSION['admin_logged_in'] = true;
			$_SESSION['csrf'] = bin2hex(random_bytes(32));
			header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
			exit;
		}
		$loginError = 'Invalid username or password.';
	}
	?>
	<!doctype html>
	<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kerntaken Admin Login</title>
	<style>body{font:16px system-ui,sans-serif;background:#f3f5f8;margin:0;min-height:100vh;display:grid;place-items:center}.card{background:white;padding:2rem;border-radius:12px;box-shadow:0 8px 30px #17203318;width:min(360px,calc(100% - 4rem))}label{display:block;margin:.9rem 0 .3rem}input,button{box-sizing:border-box;width:100%;padding:.7rem;border:1px solid #ccd2dc;border-radius:6px;font:inherit}button{margin-top:1.2rem;background:#2457c5;color:white;border:0;cursor:pointer}.error{color:#a32222}</style></head>
	<body><main class="card"><h1>Admin login</h1><?php if ($loginError): ?><p class="error"><?= h($loginError) ?></p><?php endif; ?>
	<form method="post"><input type="hidden" name="action" value="login"><label for="username">Username</label><input id="username" name="username" value="admin" required autocomplete="username"><label for="password">Password</label><input id="password" name="password" type="password" value="admin123" required autocomplete="current-password"><button type="submit">Log in</button></form></main></body></html>
	<?php
	exit;
}

if (!isset($_SESSION['csrf'])) {
	$_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$message = '';
$error = '';
$columns = [];
$rows = [];
$primary = null;
$db = null;

try {
	$db = dbConnection();
	$columns = runQuery($db, 'SHOW COLUMNS FROM `kerntaken`');
	foreach ($columns as $column) {
		if ($column['Key'] === 'PRI') {
			$primary = $column['Field'];
			break;
		}
	}
	if ($primary === null && $columns) {
		$primary = $columns[0]['Field'];
	}
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'logout') {
		if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
			throw new RuntimeException('Invalid request token. Refresh the page and try again.');
		}
		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'create' || $action === 'update') {
			$fields = [];
			$values = [];
			foreach ($columns as $column) {
				$name = $column['Field'];
				if ($action === 'update' && $name === $primary) {
					continue;
				}
				if ($action === 'create' && stripos($column['Extra'], 'auto_increment') !== false) {
					continue;
				}
				if (array_key_exists($name, $_POST['fields'] ?? [])) {
					$fields[] = $name;
					$values[] = (string) $_POST['fields'][$name];
				}
			}
			if (!$fields) {
				throw new RuntimeException('There are no editable fields in this request.');
			}
			if ($action === 'create') {
				$sql = 'INSERT INTO `kerntaken` (' . implode(', ', array_map('identifier', $fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')';
				runChange($db, $sql, $values);
				$message = 'Kerntaak added.';
			} else {
				$sets = array_map(fn($field) => identifier($field) . ' = ?', $fields);
				$values[] = (string) ($_POST['id'] ?? '');
				runChange($db, 'UPDATE `kerntaken` SET ' . implode(', ', $sets) . ' WHERE ' . identifier($primary) . ' = ?', $values);
				$message = 'Kerntaak updated.';
			}
		} elseif ($action === 'delete') {
			runChange($db, 'DELETE FROM `kerntaken` WHERE ' . identifier($primary) . ' = ?', [(string) ($_POST['id'] ?? '')]);
			$message = 'Kerntaak deleted.';
		}
	} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
		if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
			throw new RuntimeException('Invalid request token.');
		}
		$_SESSION = [];
		session_destroy();
		header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
		exit;
	}
	$rows = runQuery($db, 'SELECT * FROM `kerntaken`');
} catch (Throwable $exception) {
	$error = $exception->getMessage();
}

function fieldInput(array $column, $value = ''): string
{
	$name = (string) $column['Field'];
	$type = strtolower((string) $column['Type']);
	$label = h($name);
	$escaped = h($value);
	if (preg_match('/\b(text|blob|json)\b/', $type)) {
		return '<label>' . $label . '<textarea name="fields[' . $label . ']" rows="3">' . $escaped . '</textarea></label>';
	}
	$inputType = preg_match('/int|decimal|numeric|float|double/', $type) ? 'number' : 'text';
	$step = preg_match('/decimal|numeric|float|double/', $type) ? ' step="any"' : '';
	if (str_starts_with($type, 'date') && !str_contains($type, 'time')) {
		$inputType = 'date';
	} elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
		$inputType = 'datetime-local';
	}
	return '<label>' . $label . '<input type="' . $inputType . '"' . $step . ' name="fields[' . $label . ']" value="' . $escaped . '"></label>';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kerntaken Admin</title>
<style>body{font:15px system-ui,sans-serif;background:#f3f5f8;color:#202633;margin:0}.wrap{max-width:1100px;margin:2rem auto;padding:0 1rem}.top,.panel{background:#fff;border-radius:10px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 4px 18px #17203310}.top{display:flex;justify-content:space-between;align-items:center}h1,h2{margin:.2rem 0 1rem}form.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.8rem}label{display:block;font-weight:600}input,textarea,button{font:inherit;box-sizing:border-box;width:100%;padding:.6rem;border:1px solid #cbd2dd;border-radius:5px;margin-top:.3rem}button{cursor:pointer;background:#2457c5;color:#fff;border:0}button.danger{background:#a32222}.logout{width:auto;padding:.55rem 1rem}table{border-collapse:collapse;width:100%;background:#fff}th,td{text-align:left;padding:.65rem;border-bottom:1px solid #e5e8ed;vertical-align:top;overflow-wrap:anywhere}th{background:#f8f9fb}.record{margin:1rem 0;padding:1rem;border:1px solid #e1e5eb;border-radius:8px}.record form{margin-top:.7rem}.notice{padding:.8rem;background:#e8f5ec;color:#17632d;border-radius:6px}.error{padding:.8rem;background:#fff0f0;color:#922}.muted{color:#687386}@media(max-width:650px){.table-wrap{overflow:auto}}</style></head>
<body><main class="wrap"><header class="top"><h1>Kerntaken</h1><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><button class="logout" type="submit">Log out</button></form></header>
<?php if ($message): ?><p class="notice"><?= h($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<?php if ($columns && $primary !== null): ?>
<section class="panel"><h2>Add kerntaak</h2><form class="fields" method="post"><input type="hidden" name="action" value="create"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<?php foreach ($columns as $column) { if (stripos($column['Extra'], 'auto_increment') === false) echo fieldInput($column); } ?><button type="submit">Add</button></form></section>
<section class="panel"><h2>Existing kerntaken (<?= count($rows) ?>)</h2>
<?php if (!$rows): ?><p class="muted">No records found.</p><?php else: ?>
<?php foreach ($rows as $row): ?><article class="record"><strong><?= h($primary) ?>: <?= h($row[$primary] ?? '') ?></strong>
<form class="fields" method="post"><input type="hidden" name="action" value="update"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= h($row[$primary] ?? '') ?>">
<?php foreach ($columns as $column) { if ($column['Field'] !== $primary) echo fieldInput($column, $row[$column['Field']] ?? ''); } ?><button type="submit">Save changes</button></form>
<form method="post" onsubmit="return confirm('Delete this kerntaak?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= h($row[$primary] ?? '') ?>"><button class="danger" type="submit">Delete</button></form></article><?php endforeach; ?>
<?php endif; ?></section><?php endif; ?></main></body></html>
