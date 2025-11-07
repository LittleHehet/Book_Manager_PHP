<?php
// config/setup.php

/**
 * Crea la carpeta /data si no existe.
 */
function crearDirectorioData(string $rutaData): void {
    if (!is_dir($rutaData)) {
        mkdir($rutaData, 0777, true);
    }
}

/**
 * Conecta (o crea) la base SQLite en $rutaBD, con ERRMODE=EXCEPTION y foreign_keys ON.
 */
function conectarBD(string $rutaBD): PDO {
    $pdo = new PDO('sqlite:' . $rutaBD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Asegurar respetar FK con ON DELETE CASCADE
    $pdo->exec("PRAGMA foreign_keys = ON");
    return $pdo;
}

/**
 * Crea tablas base y de módulos (idempotente).
 * - users
 * - books
 * - categories
 * - book_category (N–M)
 * - ratings
 */
function crearTablas(PDO $pdo): void {
    $sql = <<<SQL
    -- Usuarios
    CREATE TABLE IF NOT EXISTS users (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      username   TEXT NOT NULL UNIQUE,
      password   TEXT NOT NULL,
      email      TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- Libros
    CREATE TABLE IF NOT EXISTS books (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      title      TEXT NOT NULL,
      author     TEXT NOT NULL,
      year       INTEGER,
      genre      TEXT,
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    -- Categorías
    CREATE TABLE IF NOT EXISTS categories (
      id   INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL UNIQUE
    );

    -- Relación N–M libros/categorías
    CREATE TABLE IF NOT EXISTS book_category (
      book_id     INTEGER NOT NULL,
      category_id INTEGER NOT NULL,
      PRIMARY KEY (book_id, category_id),
      FOREIGN KEY (book_id)     REFERENCES books(id)      ON DELETE CASCADE,
      FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    );

    -- Calificaciones (1..5)
    CREATE TABLE IF NOT EXISTS ratings (
      user_id    INTEGER NOT NULL,
      book_id    INTEGER NOT NULL,
      stars      INTEGER NOT NULL CHECK (stars BETWEEN 1 AND 5),
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      updated_at TEXT NOT NULL DEFAULT (datetime('now')),
      PRIMARY KEY (user_id, book_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    );
    SQL;

    $pdo->exec($sql);

    // (Opcional) Trigger para actualizar updated_at en ratings al modificar stars
    $pdo->exec("
      CREATE TRIGGER IF NOT EXISTS trg_ratings_update
      AFTER UPDATE OF stars ON ratings
      FOR EACH ROW
      BEGIN
        UPDATE ratings SET updated_at = datetime('now')
        WHERE user_id = OLD.user_id AND book_id = OLD.book_id;
      END;
    ");

    // Crear índices en bloque
    crearIndices($pdo);
}

/**
 * Crea índices útiles (idempotente).
 */
function crearIndices(PDO $pdo): void {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_title   ON books(title)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_author  ON books(author)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_genre   ON books(genre)");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_categories_name ON categories(name)");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bc_book ON book_category(book_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bc_cat  ON book_category(category_id)");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_book ON ratings(book_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ratings_user ON ratings(user_id)");
}

/**
 * Inserta algunos libros de ejemplo (solo la primera vez).
 */
function insertarLibrosEjemplo(PDO $pdo): void {
    $libros = [
        ['Cien años de soledad',      'Gabriel García Márquez', 1967, 'Realismo mágico'],
        ['Don Quijote de la Mancha',  'Miguel de Cervantes',    1605, 'Novela'],
        ['El Principito',             'Antoine de Saint-Exupéry',1943, 'Fábula']
    ];
    $stmt = $pdo->prepare("INSERT INTO books (title, author, year, genre) VALUES (:t, :a, :y, :g)");
    foreach ($libros as [$t,$a,$y,$g]) {
        $stmt->execute([':t'=>$t, ':a'=>$a, ':y'=>$y, ':g'=>$g]);
    }
}

/**
 * Genera config/database.php (idempotente) con:
 * - variable $pdo ya conectada
 * - funciones db() y getPDO() (singleton)
 * Mantiene compatibilidad con código que espera $pdo o funciones.
 */
function generarArchivoConexion(string $rutaConfig, string $rutaBD): void {
    if (!is_dir($rutaConfig)) mkdir($rutaConfig, 0777, true);

    // Construir ruta absoluta a /data/database.sqlite desde database.php
    $rutaRelativa = "__DIR__ . '/../data/" . basename($rutaBD) . "'";

    $contenido = <<<'PHP'
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
PHP;

    file_put_contents($rutaConfig . '/database.php', $contenido);
}
