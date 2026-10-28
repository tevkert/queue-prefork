-- One database per app container, and a user that may create more (hp_*) for tests.
CREATE DATABASE IF NOT EXISTS hp_stock;
CREATE DATABASE IF NOT EXISTS hp_prefork;
CREATE USER IF NOT EXISTS 'hp'@'%' IDENTIFIED BY 'hp';
GRANT ALL PRIVILEGES ON `hp\_%`.* TO 'hp'@'%';
FLUSH PRIVILEGES;
