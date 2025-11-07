<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/auth_guard.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to){ header("Location: {$to}"); exit; }

/* CSRF token */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* Obtener PDO */
$pdo = null;
try {
  require_once __DIR__ . '/config/database.php';
  if (isset($pdo) && $pdo instanceof PDO) {
    // ok
  } elseif (function_exists('db')) {
    $pdo = db();
  } elseif (function_exists('getPDO')) {
    $pdo = getPDO();
  } else {
    throw new RuntimeException('No PDO');
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // (Opcional) endurecer a nivel BD: índice único aproximado
  try {
    $pdo->exec("
      CREATE UNIQUE INDEX IF NOT EXISTS ux_books_title_author_year
      ON books (title, author, COALESCE(year, -1))
    ");
  } catch (Throwable $e) { /* tolerante */ }

} catch (Throwable $e) {
  $_SESSION['flash'] = 'No se pudo conectar a la base de datos.';
  $_SESSION['flash_type'] = 'error';
  redirect('add.php');
}

/* Helpers reutilizados */
function ensureCategories(PDO $pdo, array $names): array {
  $ids = [];
  $ins = $pdo->prepare("INSERT OR IGNORE INTO categories(name) VALUES(:n)");
  $sel = $pdo->prepare("SELECT id FROM categories WHERE name = :n");
  foreach ($names as $n) {
    $n = trim($n);
    if ($n === '') continue;
    $ins->execute([':n' => $n]);
    $sel->execute([':n' => $n]);
    $ids[] = (int)$sel->fetchColumn();
  }
  return array_values(array_filter($ids, fn($v)=>$v>0));
}
function setBookCategories(PDO $pdo, int $bookId, array $catIds): void {
  $pdo->prepare("DELETE FROM book_category WHERE book_id = :b")->execute([':b'=>$bookId]);
  $ins = $pdo->prepare("INSERT INTO book_category(book_id, category_id) VALUES(:b,:c)");
  foreach ($catIds as $cid) {
    $ins->execute([':b'=>$bookId, ':c'=>$cid]);
  }
}

/**
 * Busca si ya existe un libro con el mismo título + autor (+ año opcional).
 * Devuelve el id si existe, o null si no existe.
 */
function findExistingBook(PDO $pdo, string $title, string $author, ?int $year): ?int {
  if ($year === null) {
    $sql = "
      SELECT id
      FROM books
      WHERE LOWER(TRIM(title)) = LOWER(TRIM(:t))
        AND LOWER(TRIM(author)) = LOWER(TRIM(:a))
        AND year IS NULL
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$title, ':a'=>$author]);
  } else {
    $sql = "
      SELECT id
      FROM books
      WHERE LOWER(TRIM(title)) = LOWER(TRIM(:t))
        AND LOWER(TRIM(author)) = LOWER(TRIM(:a))
        AND year = :y
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$title, ':a'=>$author, ':y'=>$year]);
  }
  $id = $st->fetchColumn();
  return $id === false ? null : (int)$id;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    $errors['__global'] = 'CSRF token inválido.';
  }

  $title   = trim((string)($_POST['title'] ?? ''));
  $author  = trim((string)($_POST['author'] ?? ''));
  $year    = trim((string)($_POST['year'] ?? ''));
  $genre   = trim((string)($_POST['genre'] ?? ''));
  $rawCats = trim((string)($_POST['categories'] ?? ''));
  $catNames = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawCats)))));

  if ($title === '')  $errors['title']  = 'Título requerido.';
  if ($author === '') $errors['author'] = 'Autor requerido.';

  // Chequeo de duplicado antes de guardar
  if (!$errors) {
    $yearInt = ($year === '') ? null : (int)$year;
    $dupId = findExistingBook($pdo, $title, $author, $yearInt);
    if (!is_null($dupId)) {
      $errors['__global'] =
        'Este libro ya existe en la biblioteca. ' .
        'Puedes actualizarlo desde <a href="edit.php?id=' . (int)$dupId . '">Editar libro #' . (int)$dupId . '</a>.';
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("INSERT INTO books (title, author, year, genre) VALUES (:title, :author, :year, :genre)");
      $stmt->bindValue(':title',  $title,  PDO::PARAM_STR);
      $stmt->bindValue(':author', $author, PDO::PARAM_STR);
      if ($year === '') $stmt->bindValue(':year', null, PDO::PARAM_NULL);
      else $stmt->bindValue(':year', (int)$year, PDO::PARAM_INT);
      $stmt->bindValue(':genre', ($genre === '' ? null : $genre), ($genre === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
      $stmt->execute();

      $bookId = (int)$pdo->lastInsertId();
      if (!empty($catNames)) {
        $catIds = ensureCategories($pdo, $catNames);
        setBookCategories($pdo, $bookId, $catIds);
      }

      $pdo->commit();
      $_SESSION['flash'] = 'Libro agregado desde Google Books.';
      $_SESSION['flash_type'] = 'success';
      redirect('index.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['__global'] = 'Error guardando el libro.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Buscar en Google Books — Book Manager Pro</title>
  <link rel="stylesheet" href="assets/css/style.css" />
  <link rel="stylesheet" href="assets/css/google-books.css" />
  <meta name="bm-csrf" content="<?= h($_SESSION['csrf']) ?>">
</head>
<body>
  <div class="top-navbar">
    <div class="navbar-container">
      <div class="navbar-brand">
        <span class="brand-icon">📚</span>
        <div class="brand-text">
          <h1>Book Manager</h1>
          <small class="brand-subtitle">Buscar</small>
        </div>
      </div>

      <nav class="navbar-menu">
        <a class="menu-item" href="index.php">Biblioteca</a>
        <a class="menu-item" href="add.php">Agregar</a>
        <a class="menu-item active" href="add_from_google.php">Buscar en Google</a>
        <a href="reports.php" class="menu-item"><span>Reportes</span></a>
      </nav>

      <div class="navbar-user">
        <!-- Toggle de tema igual que index -->
        <button id="themeToggle" class="menu-item" type="button" title="Cambiar tema">
          <span id="themeIcon">🌙</span>
        </button>

        <div class="user-name">
          <span><?= h($_SESSION['username'] ?? 'usuario') ?></span>
        </div>

        <!-- Salir con CSRF -->
        <form action="logout.php" method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer">
            Salir
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="page-header-bar">
      <div class="header-title">
        <span class="title-icon">🔎</span>
        <h2>Buscar libros (Google Books)</h2>
      </div>
      <div class="counter-compact">
        <strong class="count-num">0</strong> resultados
      </div>
    </div>

    <?php if (!empty($errors['__global'])): ?>
      <div class="alert alert-error">
        <span class="alert-icon">⚠️</span>
        <span><?= $errors['__global'] /* contiene un <a> seguro con id casteado */ ?></span>
      </div>
    <?php endif; ?>

    <div class="form-card">
      <!-- Barra de búsqueda: igualada a index y botones con mismo tamaño -->
      <div class="search-bar" role="search" aria-label="Buscar libros">
        <input id="gb-query" type="search" placeholder="Buscar por título, autor, ISBN..." aria-label="Buscar libros">
        <button id="gb-search" class="btn btn-primary">Buscar</button>
        <a class="btn btn-secondary" href="add.php">Crear manual</a>
      </div>

      <div id="gb-results" class="gb-results-grid" style="margin-top:14px;"></div>
    </div>
  </div>

  <script src="assets/js/google-books.js"></script>
  <script>
  (function(){
    const html = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    const ico  = document.getElementById('themeIcon');
    if(!btn || !ico) return;

    const saved = localStorage.getItem('bm-theme');
    if (saved === 'light') html.setAttribute('data-theme','light');

    const applyIcon = () => {
      const isLight = html.getAttribute('data-theme') === 'light';
      ico.textContent = isLight ? '🌞' : '🌙';
    };
    applyIcon();

    btn.addEventListener('click', () => {
      const isLight = html.getAttribute('data-theme') === 'light';
      if (isLight) {
        html.removeAttribute('data-theme');         // vuelve a oscuro
        localStorage.setItem('bm-theme','dark');
      } else {
        html.setAttribute('data-theme','light');    // aplica claro
        localStorage.setItem('bm-theme','light');
      }
      applyIcon();
    });
  })();
  </script>
</body>
</html>
