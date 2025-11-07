<?php
// edit.php — versión conectada a BD (SQLite + PDO)
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
   Conexión y verificación de instalación
   ========================= */
$pdo = null;
try {
  require_once __DIR__ . '/config/database.php';

  // Soportar variantes comunes
  if (isset($pdo) && $pdo instanceof PDO) {
      // ok
  } elseif (function_exists('db')) {
      $pdo = db();
  } elseif (function_exists('getPDO')) {
      $pdo = getPDO();
  } else {
      throw new RuntimeException('No se pudo obtener la conexión PDO desde config/database.php');
  }

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Verificar tablas mínimas
  $hasBooks = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'")->fetchColumn();
  $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
  if (!$hasBooks || !$hasUsers) {
    redirect('install.php');
  }
} catch (Throwable $e) {
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

/** Obtiene categorías (nombres) de un libro */
function getBookCategoryNames(PDO $pdo, int $bookId): array {
  $q = $pdo->prepare("
    SELECT c.name
    FROM categories c
    JOIN book_category bc ON bc.category_id = c.id
    WHERE bc.book_id = :b
    ORDER BY LOWER(c.name)
  ");
  $q->execute([':b'=>$bookId]);
  return $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/* =========================
   Cargar libro por ID
   ========================= */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('ID inválido');
}

try {
  $stmt = $pdo->prepare("SELECT id, title, author, year, genre, created_at FROM books WHERE id = :id");
  $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  $stmt->execute();
  $book = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$book) {
    http_response_code(404);
    exit('Libro no encontrado');
  }
} catch (Throwable $e) {
  http_response_code(500);
  exit('Error al cargar el libro');
}

/* =========================
   Estado del formulario
   ========================= */
$errors = [];
$title  = (string)$book['title'];
$author = (string)$book['author'];
$year   = is_null($book['year']) ? '' : (string)$book['year'];
$genre  = (string)($book['genre'] ?? '');

// ✅ NUEVO: cargar categorías actuales para mostrarlas en el input
$currentCatNames = getBookCategoryNames($pdo, $id);

/* =========================
   Manejo POST (actualizar)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('CSRF token inválido');
  }

  $title  = trim((string)($_POST['title'] ?? ''));
  $author = trim((string)($_POST['author'] ?? ''));
  $year   = trim((string)($_POST['year'] ?? ''));
  $genre  = trim((string)($_POST['genre'] ?? ''));

  // NUEVO: categorías desde el POST
  $rawCats   = (string)($_POST['categories'] ?? '');
  $catNames  = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawCats)))));

  if ($title === '')  $errors['title']  = 'El título es obligatorio.';
  if ($author === '') $errors['author'] = 'El autor es obligatorio.';
  if ($year !== '' && (!ctype_digit($year) || (int)$year < 0 || (int)$year > 2100)) {
    $errors['year'] = 'Año inválido (0–2100).';
  }
  if (mb_strlen($genre) > 100) $errors['genre'] = 'Máximo 100 caracteres.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("
        UPDATE books
           SET title = :title,
               author = :author,
               year = :year,
               genre = :genre
         WHERE id = :id
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
      $stmt->bindValue(':id',     $id,     PDO::PARAM_INT);

      $stmt->execute();

      // ✅ Reemplazar categorías del libro
      $catIds = ensureCategories($pdo, $catNames);
      setBookCategories($pdo, $id, $catIds);

      $pdo->commit();

      $_SESSION['flash'] = 'Libro actualizado correctamente.';
      $_SESSION['flash_type'] = 'success';
      redirect('index.php');
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors['__global'] = 'Ocurrió un error al actualizar. Intenta de nuevo.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<script>
try {
  var t = localStorage.getItem('bm-theme');
  if (t === 'light') {
    document.documentElement.setAttribute('data-theme','light');
  } else {
    document.documentElement.removeAttribute('data-theme'); // oscuro por defecto
  }
} catch(e){}
</script>
<link rel="stylesheet" href="assets/css/style.css" />

<head>
  <meta charset="utf-8" />
  <title>Editar libro — Book Manager Pro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
  <div class="top-navbar">
    <div class="navbar-container">
      <div class="navbar-brand">
        <span class="brand-icon">📚</span>
        <div class="brand-text">
          <h1>Book Manager</h1>
          <small class="brand-subtitle">Editar libro</small>
        </div>
      </div>
      <nav class="navbar-menu">
        <a class="menu-item" href="index.php">Biblioteca</a>
        <a class="menu-item" href="add.php">Agregar</a>
        <a href="reports.php" class="menu-item"><span>Reportes</span></a>
      </nav>
      <div class="navbar-user">
        <button id="themeToggle" class="logout-link" type="button" title="Cambiar tema">
          <span id="themeIcon" aria-hidden="true">🌙 Tema</span>
        </button>
        <div class="user-name"><span><?= h($_SESSION['username'] ?? 'usuario') ?></span></div>

        <!-- ✅ Logout aquí -->
        <form action="logout.php" method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer">
            Salir
          </button>
        </form>
        <!-- ✅ fin Logout -->
      </div>
    </div>
  </div>

  <div class="container">
    <div class="page-header-bar">
      <div class="header-title"><span class="title-icon">✏️</span><h2>Editar: <?= h($title) ?></h2></div>
      <div class="counter-compact"><strong class="count-num">ID <?= h((string)$id) ?></strong></div>
    </div>

    <?php if (isset($errors['__global'])): ?>
      <div class="alert alert-error">
        <span class="alert-icon">⚠️</span>
        <span><?= h($errors['__global']) ?></span>
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

      <!-- ✅ NUEVO: Categorías -->
      <div class="form-row">
        <div class="form-col">
          <label for="categories">Categorías (separadas por coma)</label>
          <input id="categories" name="categories" type="text"
                 value="<?= h(implode(', ', $currentCatNames)) ?>"
                 placeholder="Ej: Ciencia ficción, IA, Clásicos">
        </div>
      </div>
      <!-- ✅ fin Categorías -->

      <div class="actions">
        <a class="btn btn-secondary" href="index.php">Volver</a>
        <a class="danger-link" href="delete.php?id=<?= h((string)$id) ?>"
           onclick="return confirm('¿Eliminar este libro?');">Eliminar</a>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
  
  <script>
(function(){
  const html = document.documentElement;
  const btn  = document.getElementById('themeToggle');
  const ico  = document.getElementById('themeIcon');

  // Sincroniza el ícono con el tema actual
  const applyIcon = () => {
    if (!ico) return;
    const isLight = html.getAttribute('data-theme') === 'light';
    ico.textContent = isLight ? '🌞' : '🌙';
  };
  applyIcon();

  // Si hay botón, habilita el toggle
  if (btn) {
    btn.addEventListener('click', () => {
      const isLight = html.getAttribute('data-theme') === 'light';
      if (isLight) {
        html.removeAttribute('data-theme');           // oscuro
        localStorage.setItem('bm-theme','dark');
      } else {
        html.setAttribute('data-theme','light');      // claro
        localStorage.setItem('bm-theme','light');
      }
      applyIcon();
    });
  }
})();
</script>

  
</body>
</html>
