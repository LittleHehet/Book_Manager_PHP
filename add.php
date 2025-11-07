<?php
// add.php — versión conectada a BD (SQLite + PDO)
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/auth_guard.php';

/* =========================
   Utilidades
   ========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to){ header("Location: {$to}"); exit; }

/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* =========================
   Conexión y modelos
   ========================= */
$pdo = null;
try {
  require_once __DIR__ . '/config/database.php';

  // Soportar variantes comunes en database.php
  if (isset($pdo) && $pdo instanceof PDO) {
      // OK
  } elseif (function_exists('db')) {
      $pdo = db();
  } elseif (function_exists('getPDO')) {
      $pdo = getPDO();
  } else {
      throw new RuntimeException('No se pudo obtener la conexión PDO desde config/database.php');
  }

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Carga opcional de modelos (no estrictamente necesario aquí)
  @require_once __DIR__ . '/models/Book.php';
  @require_once __DIR__ . '/models/User.php';

  // Verificar tablas mínimas
  $hasBooks = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'")->fetchColumn();
  $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
  if (!$hasBooks || !$hasUsers) {
    redirect('install.php');
  }

  // (Opcional) Endurecer a nivel BD: índice único aproximado (tolerante)
  try {
    $pdo->exec("
      CREATE UNIQUE INDEX IF NOT EXISTS ux_books_title_author_year
      ON books (title, author, COALESCE(year, -1))
    ");
  } catch (Throwable $e) { /* tolerante */ }

} catch (Throwable $e) {
  // Si no hay BD/tablas aún, ir al instalador
  redirect('install.php');
}

/* =========================
   Helpers de Categorías
   ========================= */
/** Inserta categorías inexistentes y retorna IDs en orden de $names */
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

/** Reemplaza el set de categorías de un libro */
function setBookCategories(PDO $pdo, int $bookId, array $catIds): void {
  $pdo->prepare("DELETE FROM book_category WHERE book_id = :b")->execute([':b'=>$bookId]);
  $ins = $pdo->prepare("INSERT INTO book_category(book_id, category_id) VALUES(:b,:c)");
  foreach ($catIds as $cid) {
    $ins->execute([':b'=>$bookId, ':c'=>$cid]);
  }
}

/** Busca si ya existe libro con mismo título+autor (+ año opcional). Retorna id o null. */
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

/* =========================
   Estado del formulario
   ========================= */
$errors = [];
$title = $author = $genre = '';
$year = '';

/* =========================
   Manejo POST
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // CSRF
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('CSRF token inválido');
  }

  // Sanitización básica (luego escapamos al imprimir)
  $title  = trim((string)($_POST['title'] ?? ''));
  $author = trim((string)($_POST['author'] ?? ''));
  $year   = trim((string)($_POST['year'] ?? ''));   // podría venir vacío
  $genre  = trim((string)($_POST['genre'] ?? ''));  // opcional

  // Categorías crudas del form
  $rawCats  = (string)($_POST['categories'] ?? '');
  $catNames = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawCats)))));

  // Validaciones
  if ($title === '')  { $errors['title']  = 'El título es obligatorio.'; }
  if ($author === '') { $errors['author'] = 'El autor es obligatorio.'; }
  if ($year !== '') {
    if (!ctype_digit($year) || (int)$year < 0 || (int)$year > 2100) {
      $errors['year'] = 'Año inválido (0–2100).';
    }
  }
  if (mb_strlen($genre) > 100) {
    $errors['genre'] = 'Máximo 100 caracteres.';
  }

  // ✅ Chequeo de duplicado antes de guardar
  if (!$errors) {
    $yearInt = ($year === '') ? null : (int)$year;
    $dupId = findExistingBook($pdo, $title, $author, $yearInt);
    if (!is_null($dupId)) {
      // Mensaje con link seguro a editar
      $errors['__global'] =
        'Este libro ya existe en la biblioteca. ' .
        'Puedes actualizarlo desde <a href="edit.php?id=' . (int)$dupId . '">Editar libro #' . (int)$dupId . '</a>.';
    }
  }

  // Insertar si todo OK y no duplicado
  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("
        INSERT INTO books (title, author, year, genre)
        VALUES (:title, :author, :year, :genre)
      ");

      // year puede ser NULL
      if ($year === '') {
        $stmt->bindValue(':year', null, PDO::PARAM_NULL);
      } else {
        $stmt->bindValue(':year', (int)$year, PDO::PARAM_INT);
      }

      // genre puede ser NULL
      $stmt->bindValue(':genre', ($genre === '' ? null : $genre), ($genre === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));

      $stmt->bindValue(':title',  $title,  PDO::PARAM_STR);
      $stmt->bindValue(':author', $author, PDO::PARAM_STR);

      $stmt->execute();

      $bookId = (int)$pdo->lastInsertId();

      // Set de categorías si vienen
      if (!empty($catNames)) {
        $catIds = ensureCategories($pdo, $catNames);
        setBookCategories($pdo, $bookId, $catIds);
      }

      $pdo->commit();

      $_SESSION['flash'] = 'Libro agregado correctamente.';
      $_SESSION['flash_type'] = 'success';
      redirect('index.php');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // Si falló por unique index de refuerzo, también mostramos duplicado genérico
      if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'constraint')) {
        $errors['__global'] = 'Ya existe un libro con ese título, autor y año.';
      } else {
        $errors['__global'] = 'Ocurrió un error al guardar. Intenta de nuevo.';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Agregar libro — Book Manager Pro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
  <!-- Top Navbar -->
  <div class="top-navbar">
    <div class="navbar-container">
      <div class="navbar-brand">
        <span class="brand-icon">📚</span>
        <div class="brand-text">
          <h1>Book Manager</h1>
          <small class="brand-subtitle">Agregar libro</small>
        </div>
      </div>
      <nav class="navbar-menu">
        <a class="menu-item" href="index.php">Biblioteca</a>
        <a class="menu-item active" href="add.php">Agregar</a>
        <a class="menu-item" href="add_from_google.php">Buscar en Google</a>
        <a href="reports.php" class="menu-item"><span>Reportes</span></a>
      </nav>
      <div class="navbar-user">
        <!-- Toggle tema igual al resto -->
        <button id="themeToggle" class="menu-item" type="button" title="Cambiar tema">
          <span id="themeIcon" aria-hidden="true">🌙</span>
        </button>

        <div class="user-name"><span><?= h($_SESSION['username'] ?? 'usuario') ?></span></div>

        <!-- Logout -->
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
      <div class="header-title"><span class="title-icon">➕</span><h2>Nuevo libro</h2></div>
      <div class="counter-compact"><strong class="count-num">+1</strong> libro</div>
    </div>

    <?php if (isset($errors['__global'])): ?>
      <div class="alert alert-error">
        <span class="alert-icon">⚠️</span>
        <!-- Contiene un <a> seguro con id casteado -->
        <span><?= $errors['__global'] ?></span>
      </div>
    <?php endif; ?>

    <form method="post" class="form-card" novalidate>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

      <div class="form-row">
        <div class="form-col">
          <label for="title">Título *</label>
          <input id="title" name="title" type="text" required value="<?= h($title) ?>">
          <?php if(isset($errors['title'])): ?><div class="error"><?= h($errors['title']) ?></div><?php endif; ?>
        </div>
        <div class="form-col">
          <label for="author">Autor *</label>
          <input id="author" name="author" type="text" required value="<?= h($author) ?>">
          <?php if(isset($errors['author'])): ?><div class="error"><?= h($errors['author']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="form-row">
        <div class="form-col">
          <label for="year">Año</label>
          <input id="year" name="year" type="number" min="0" max="2100" value="<?= h($year) ?>">
          <?php if(isset($errors['year'])): ?><div class="error"><?= h($errors['year']) ?></div><?php endif; ?>
        </div>
        <div class="form-col">
          <label for="genre">Género</label>
          <input id="genre" name="genre" type="text" maxlength="100" value="<?= h($genre) ?>">
          <?php if(isset($errors['genre'])): ?><div class="error"><?= h($errors['genre']) ?></div><?php endif; ?>
        </div>
      </div>

      <!-- Categorías -->
      <div class="form-row">
        <div class="form-col">
          <label for="categories">Categorías (separadas por coma)</label>
          <input id="categories" name="categories" type="text"
                 placeholder="Ej: Ciencia ficción, IA, Clásicos"
                 value="<?= h($_POST['categories'] ?? '') ?>">
          <small class="text-muted">Puedes agregar varias separadas por coma.</small>
        </div>
      </div>

      <div class="actions">
        <a class="btn btn-secondary" href="index.php">Cancelar</a>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>

  <script>
  (function(){
    const html = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    const ico  = document.getElementById('themeIcon');
    if(!btn || !ico) return;

    // Preferencia guardada global
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
        html.removeAttribute('data-theme');        // vuelve al oscuro
        localStorage.setItem('bm-theme','dark');
      } else {
        html.setAttribute('data-theme','light');   // aplica claro
        localStorage.setItem('bm-theme','light');
      }
      applyIcon();
    });
  })();
  </script>
</body>
</html>

