<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('CSRF token inválido');
  }
  // En modo demo: siempre mostramos éxito sin revelar si el usuario existe
  $done = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Recuperar acceso — Book Manager Pro</title>
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
          <small class="brand-subtitle">Recuperar acceso</small>
        </div>
      </div>
    </div>
  </div>

  <div class="container">
    <?php if ($done): ?>
      <div class="alert alert-success">
        <span class="alert-icon">✅</span>
        <span>Si la cuenta existe, se enviaron instrucciones a su correo.</span>
      </div>
      <p><a class="menu-item" href="login.php">Volver a iniciar sesión</a></p>
    <?php else: ?>
      <form method="post" class="form-card">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <div class="form-row">
          <div class="form-col">
            <label for="user">Usuario o correo</label>
            <input id="user" name="user" type="text" required>
          </div>
        </div>
        <div class="actions">
          <a class="btn btn-secondary" href="login.php">Cancelar</a>
          <button class="btn btn-primary" type="submit">Enviar</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
