<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database_test.php';
require_once __DIR__ . '/../models/Book.php';
require_once __DIR__ . '/../models/User.php';

// Seguridad: asegurarnos de que la BD es SQLite en memoria
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    die(" Error: ¡Se está usando la base real en lugar de la de pruebas!");
}
