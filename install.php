<?php
// install.php — instalación idempotente (crea lo que falte y luego va a login)
require_once __DIR__ . '/config/setup.php';

$rutaData = __DIR__ . '/data';
$dbFile   = $rutaData . '/database.sqlite';
$configDir= __DIR__ . '/config';
$configPHP= $configDir . '/database.php';

// 1) Asegurar carpeta /data
crearDirectorioData($rutaData);

// 2) Conectar (crea el archivo si no existe)
$pdo = conectarBD($dbFile);

// 3) Crear tablas base (si faltan)
crearTablas($pdo);

// 4) Asegurar que haya al menos 1 usuario (si la tabla está vacía, insertar demo)
$hasUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($hasUsers === 0) {
  $usuariosIniciales = [
    ['username' => 'admin', 'password' => 'clave123'],
    ['username' => 'jose',  'password' => 'jose123'],
    ['username' => 'katherine', 'password' => 'katherine123'],    
    ['username' => 'randy',  'password' => 'randy123'],
    ['username' => 'alexia', 'password' => 'alexia123'],    
    ['username' => 'kendra',  'password' => 'kendra123'],
  ];
  $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (:u,:p)");
  foreach ($usuariosIniciales as $u) {
    $stmt->execute([
      ':u' => $u['username'],
      ':p' => password_hash($u['password'], PASSWORD_DEFAULT),
    ]);
  }
}

// 5) Insertar libros de ejemplo si la tabla está vacía
$hasBooks = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
if ($hasBooks === 0) {
  insertarLibrosEjemplo($pdo);
}

// 6) Crear índices (idempotente)
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_title  ON books(title);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_books_genre  ON books(genre);");

// 7) Tabla ratings (idempotente)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS ratings (
    user_id   INTEGER NOT NULL,
    book_id   INTEGER NOT NULL,
    stars     INTEGER NOT NULL CHECK (stars BETWEEN 1 AND 5),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (user_id, book_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
  );
  CREATE INDEX IF NOT EXISTS idx_ratings_book ON ratings(book_id);
  CREATE INDEX IF NOT EXISTS idx_ratings_user ON ratings(user_id);
");

// 8) Generar archivo de conexión si falta (idempotente)
if (!file_exists($configPHP)) {
  generarArchivoConexion($configDir, $dbFile);
}

// 9) Salida breve y redirección limpia al login
echo "<h2>Instalación verificada/completada ✅</h2>";
echo "<p>Serás redirigido al login…</p>";
header('Refresh: 2; url=login.php');
exit;
