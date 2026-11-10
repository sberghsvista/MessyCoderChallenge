<?php
include '../database.inc.php';

function getKerntaken($name)
{
	global $conn;

	$stmt = $conn->prepare('SELECT id, titel, bewijs FROM kerntaken WHERE id = ?');
	$stmt->bind_param('s', $name);
	$stmt->execute();

	$result = $stmt->get_result();
	$kerntaken = $result->fetch_assoc();

	$stmt->close();

    if ($kerntaken) {
        echo "<h2>" . htmlspecialchars($kerntaken['id'] . ' - ' . $kerntaken['titel']) . "</h2>";
        echo "<p>" . nl2br(htmlspecialchars($kerntaken['bewijs'])) . "</p>";
    } else {
        echo "<p>Geen kerntaken gevonden voor B1-K1-W1.</p>";
    }
}
?>