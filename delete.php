<?php
// delete.php — versión segura con confirmación (SQLite + PDO)
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
   Obtener ID y libro
   ========================= */
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
  $_SESSION['flash'] = 'ID inválido.';
  $_SESSION['flash_type'] = 'error';
  redirect('index.php');
}

try {
  $stmt = $pdo->prepare("SELECT id, title, author, year, genre, created_at FROM books WHERE id = :id");
  $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  $stmt->execute();
  $book = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$book) {
    $_SESSION['flash'] = '📕 Libro no encontrado.';
    $_SESSION['flash_type'] = 'error';
    redirect('index.php');
  }
} catch (Throwable $e) {
  $_SESSION['flash'] = 'Error al cargar el libro.';
  $_SESSION['flash_type'] = 'error';
  redirect('index.php');
}

/* =========================
   Si POST => eliminar
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('CSRF token inválido');
  }

  try {
    $del = $pdo->prepare("DELETE FROM books WHERE id = :id");
    $del->bindValue(':id', $id, PDO::PARAM_INT);
    $del->execute();

    $_SESSION['flash'] = 'Libro eliminado correctamente.';
    $_SESSION['flash_type'] = 'success';
    redirect('index.php');
  } catch (Throwable $e) {
    $_SESSION['flash'] = 'No se pudo eliminar el libro. Intenta de nuevo.';
    $_SESSION['flash_type'] = 'error';
    redirect('index.php');
  }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Eliminar libro — Book Manager Pro</title>
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
          <small class="brand-subtitle">Eliminar libro</small>
        </div>
      </div>
      <nav class="navbar-menu">
        <a class="menu-item" href="index.php">Biblioteca</a>
        <a class="menu-item" href="add.php">Agregar</a>
		<a href="reports.php" class="menu-item"><span>Reportes</span></a>
   
      </nav>
      <div class="navbar-user">
			<button id="themeToggle" class="logout-link" type="button" title="Cambiar tema">
			<span id="themeIcon" aria-hidden="true">🌙</span>
			</button>
        <div class="user-name"><span><?= h($_SESSION['username'] ?? 'usuario') ?></span></div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="page-header-bar">
      <div class="header-title">
        <span class="title-icon">🗑️</span>
        <h2>Eliminar: <?= h($book['title']) ?></h2>
      </div>
      <div class="counter-compact"><strong class="count-num">ID <?= h((string)$book['id']) ?></strong></div>
    </div>

    <div class="card">
      <p style="margin-bottom:10px;color:var(--text-secondary);">
        Esta acción no se puede deshacer. ¿Seguro que deseas eliminar el libro <strong><?= h($book['title']) ?></strong>?
      </p>

      <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
        <a class="btn btn-secondary" href="index.php">Cancelar</a>

        <form method="post" style="display:inline;">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
          <input type="hidden" name="id"   value="<?= h((string)$book['id']) ?>">
          <button class="btn btn-danger" type="submit">Eliminar definitivamente</button>
        </form>
      </div>
    </div>
  </div>
  
<script>
(function(){
  const html = document.documentElement;
  const btn  = document.getElementById('themeToggle');
  const ico  = document.getElementById('themeIcon');
  if(!btn || !ico) return;

  // ✅ Cargar preferencia global
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
      localStorage.setItem('bm-theme','dark');   // 🔄 guarda preferencia
    } else {
      html.setAttribute('data-theme','light');   // aplica claro
      localStorage.setItem('bm-theme','light');  // 🔄 guarda preferencia
    }
    applyIcon();
  });
})();
</script>

  
</body>
</html>
