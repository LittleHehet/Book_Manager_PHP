<?php
// rate.php — crear/actualizar calificación (SQLite + PDO)
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

// Validar sesión
if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function backTo(string $fallback='index.php'){
  $b = isset($_POST['back']) ? (string)$_POST['back'] : $fallback;
  // Seguridad básica: solo permitir volver a páginas locales
  if (stripos($b, 'http://') === 0 || stripos($b, 'https://') === 0) $b = $fallback;
  header('Location: ' . $b);
  exit;
}

// CSRF
if (empty($_POST['csrf']) || !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$_POST['csrf'])) {
  $_SESSION['flash'] = 'Token de seguridad inválido.';
  $_SESSION['flash_type'] = 'warning';
  backTo();
}

// Validar datos
$userId = (int)$_SESSION['user_id'];
$bookId = (int)($_POST['book_id'] ?? 0);
$stars  = (int)($_POST['stars'] ?? 0);
if ($bookId <= 0 || $stars < 1 || $stars > 5) {
  $_SESSION['flash'] = 'Datos de calificación inválidos.';
  $_SESSION['flash_type'] = 'warning';
  backTo();
}

// Conexión
try {
  require_once __DIR__ . '/config/database.php';
  if (isset($pdo) && $pdo instanceof PDO) {
    // ok
  } elseif (function_exists('db')) {
    $pdo = db();
  } elseif (function_exists('getPDO')) {
    $pdo = getPDO();
  } else {
    throw new RuntimeException('No se pudo obtener conexión PDO.');
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  $_SESSION['flash'] = 'No fue posible conectar a la base de datos.';
  $_SESSION['flash_type'] = 'warning';
  backTo();
}

// Garantizar tabla ratings
$pdo->exec("
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
  CREATE INDEX IF NOT EXISTS idx_ratings_book ON ratings(book_id);
  CREATE INDEX IF NOT EXISTS idx_ratings_user ON ratings(user_id);
");

// Confirmar que el libro exista
$exists = (int)$pdo->prepare("SELECT COUNT(*) FROM books WHERE id = :id")
                   ->execute([':id'=>$bookId]) || true;
$st = $pdo->prepare("SELECT COUNT(*) FROM books WHERE id = :id");
$st->execute([':id'=>$bookId]);
if ((int)$st->fetchColumn() === 0) {
  $_SESSION['flash'] = 'El libro no existe.';
  $_SESSION['flash_type'] = 'warning';
  backTo();
}

// UPSERT de la calificación (SQLite)
$sql = "
  INSERT INTO ratings (user_id, book_id, stars)
  VALUES (:u, :b, :s)
  ON CONFLICT(user_id, book_id)
  DO UPDATE SET stars = excluded.stars, updated_at = datetime('now')
";
$q = $pdo->prepare($sql);
$q->execute([':u'=>$userId, ':b'=>$bookId, ':s'=>$stars]);

$_SESSION['flash'] = '¡Calificación guardada!';
$_SESSION['flash_type'] = 'success';
backTo();
