<?php
// categories.php — administración simple de categorías
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/auth_guard.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to){ header("Location: {$to}"); exit; }

// CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Conexión
$pdo = null;
try {
  require_once __DIR__ . '/config/database.php';
  if (isset($pdo) && $pdo instanceof PDO) { /* ok */ }
  elseif (function_exists('db')) $pdo = db();
  elseif (function_exists('getPDO')) $pdo = getPDO();
  else throw new RuntimeException('No PDO');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  redirect('install.php');
}

// Eliminar categoría (solo si no está en uso)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400); exit('CSRF token inválido');
  }
  $delId = (int)$_POST['delete_id'];
  if ($delId > 0) {
    try {
      $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM book_category WHERE category_id = :id")
                      ->execute([':id'=>$delId]) ? (int)$pdo->query("SELECT changes()")->fetchColumn() : 0;
      // El query anterior con execute+changes() no cuenta; rehagamos:
      $st = $pdo->prepare("SELECT COUNT(*) FROM book_category WHERE category_id = :id");
      $st->execute([':id'=>$delId]);
      $inUse = (int)$st->fetchColumn();

      if ($inUse === 0) {
        $del = $pdo->prepare("DELETE FROM categories WHERE id = :id");
        $del->execute([':id'=>$delId]);
        $_SESSION['flash'] = 'Categoría eliminada.';
        $_SESSION['flash_type'] = 'success';
      } else {
        $_SESSION['flash'] = 'No se puede eliminar: categoría en uso.';
        $_SESSION['flash_type'] = 'error';
      }
    } catch (Throwable $e) {
      $_SESSION['flash'] = 'Error al eliminar categoría.';
      $_SESSION['flash_type'] = 'error';
    }
  }
  redirect('categories.php');
}

// Listado
$rows = [];
try {
  $stmt = $pdo->query("
    SELECT c.id, c.name,
           (SELECT COUNT(*) FROM book_category bc WHERE bc.category_id = c.id) AS count_books
    FROM categories c
    ORDER BY LOWER(c.name)
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

// Flash
$message      = $_SESSION['flash']      ?? '';
$message_type = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash'], $_SESSION['flash_type']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Categorías — Book Manager Pro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
  <nav class="top-navbar">
    <div class="navbar-container">
      <div class="navbar-brand">
        <span class="brand-icon">🏷️</span>
        <div class="brand-text">
          <h1>Categorías</h1>
          <small class="brand-subtitle">Administración</small>
        </div>
      </div>
      <div class="navbar-menu">
        <a class="menu-item" href="index.php">Biblioteca</a>
        <a class="menu-item" href="add.php">Agregar</a>
        <a class="menu-item" href="reports.php">Reportes</a>
        <a class="menu-item active" href="categories.php">Categorías</a>
      </div>
      <div class="navbar-user">
        <div class="user-name"><span><?= h($_SESSION['username'] ?? 'usuario') ?></span></div>
        <form action="logout.php" method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <button type="submit" class="logout-link" style="background:none;border:none;cursor:pointer">
            Salir
          </button>
        </form>
      </div>
    </div>
  </nav>

  <div class="container">
    <?php if (!empty($message)): ?>
      <div class="alert alert-<?= h($message_type) ?>">
        <span class="alert-icon"><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
        <span><?= h($message) ?></span>
      </div>
    <?php endif; ?>

    <div class="page-header-bar">
      <div class="header-title">
        <span class="title-icon">🏷️</span>
        <h2>Listado de categorías</h2>
      </div>
      <div class="counter-compact">
        <span class="count-num"><?= count($rows) ?></span> categorías
      </div>
    </div>

    <section class="card">
      <table class="table-compact">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <th>Nombre</th>
            <th style="width:160px;">Libros asociados</th>
            <th style="width:180px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="4" class="text-muted">Sin categorías.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= h((string)$r['name']) ?></td>
                <td><?= (int)$r['count_books'] ?></td>
                <td>
                  <a class="btn btn-secondary" href="index.php?cat=<?= (int)$r['id'] ?>">Ver libros</a>
                  <?php if ((int)$r['count_books'] === 0): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar esta categoría?');">
                      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                      <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-danger">Eliminar</button>
                    </form>
                  <?php else: ?>
                    <button class="btn btn-danger" disabled title="No se puede eliminar: en uso">Eliminar</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </div>

  <footer class="site-footer">
    <h3 class="footer-title">Grupo 2</h3>
    <ul class="footer-grid">
      <li><span class="name">Alexia Alvarado Alfaro</span> <span class="id">(402580319)</span></li>
      <li><span class="name">Kendra Artavia Caballero</span> <span class="id">(402580003)</span></li>
      <li><span class="name">Randy Nuñez Vargas</span> <span class="id">(119100297)</span></li>
      <li><span class="name">Katherine Jara Arroyo</span> <span class="id">(402650268)</span></li>
      <li><span class="name">Jose Carballo Morales</span> <span class="id">(119060186)</span></li>
    </ul>
  </footer>
</body>
</html>
