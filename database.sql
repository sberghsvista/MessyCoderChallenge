CREATE TABLE IF NOT EXISTS kerntaken (
	id VARCHAR(10) NOT NULL,
	titel VARCHAR(200) NOT NULL,
	bewijs TEXT NULL,
	status VARCHAR(50) NULL DEFAULT 'open',
	aangemaakt_op TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	bijgewerkt_op TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_kerntaken_status (status)
) ENGINE=InnoDB;

INSERT INTO kerntaken (id, titel) VALUES
	('B1-K1-W1', 'Stemt opdracht af, plant werkzaamheden en bewaakt de voortgang'),
	('B1-K1-W2', 'Maakt een technisch ontwerp voor software'),
	('B1-K1-W3', 'Realiseert (onderdelen van) software'),
	('B1-K1-W4', 'Test software'),
	('B1-K1-W5', 'Doet verbetervoorstellen voor de software'),
	('B1-K2-W1', 'Werkt samen in een projectteam'),
	('B1-K2-W2', 'Presenteert het opgeleverde werk'),
	('B1-K2-W3', 'Evalueert de samenwerking');
