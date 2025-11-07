<?php
// Archivo generado automáticamente por install.php
// Ofrece $pdo y funciones db()/getPDO() (singleton compatibles).

static $__pdo_singleton = null;

function getPDO(): PDO {
    global $__pdo_singleton;
    if ($__pdo_singleton instanceof PDO) {
        return $__pdo_singleton;
    }
    $path = __DIR__ . '/../data/database.sqlite';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $__pdo_singleton = $pdo;
    return $__pdo_singleton;
}

/** Alias */
function db(): PDO { return getPDO(); }

// Compatibilidad con código que espera $pdo como variable global
$pdo = getPDO();