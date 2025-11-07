<?php
// login.php — redirige a install solo si falta algo; sin loops
declare(strict_types=1);

/* ---- Sesión endurecida ---- */
$secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => $secure,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

/* ---- Utils ---- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function go(string $u){ header("Location: {$u}"); exit; }

/* ---- CSRF ---- */
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));

/* ---- Verificar instalación ---- */
$dbFile   = __DIR__ . '/data/database.sqlite';
$dbConfig = __DIR__ . '/config/database.php';

$needInstall = (!file_exists($dbFile) || !file_exists($dbConfig));
$pdo = null;

if (!$needInstall) {
  try {
    require_once $dbConfig; // expone $pdo o db()/getPDO()
    if (isset($pdo) && $pdo instanceof PDO) {
      // ok
    } elseif (function_exists('db')) {
      $pdo = db();
    } elseif (function_exists('getPDO')) {
      $pdo = getPDO();
    } else {
      throw new RuntimeException('Sin PDO');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Requisitos mínimos
    $hasBooks = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'")->fetchColumn();
    $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$hasBooks || !$hasUsers) $needInstall = true;

  } catch (Throwable $e) {
    $needInstall = true;
  }
}

if ($needInstall) {
  // Delega en install.php; ahora es idempotente y no hace loop
  go('install.php');
}

/* ---- Si ya hay sesión, ir al home ---- */
if (isset($_SESSION['user_id'])) {
  go('index.php');
}

/* ---- Login ---- */
$errors = [];
$user   = '';
$pass   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    $errors['__global'] = 'CSRF inválido. Recarga.';
  }

  $user = trim((string)($_POST['username'] ?? ''));
  $pass = trim((string)($_POST['password'] ?? ''));
  if ($user === '') $errors['username'] = 'El usuario es obligatorio.';
  if ($pass === '') $errors['password'] = 'La contraseña es obligatoria.';

  if (!$errors) {
    try {
      $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = :u LIMIT 1");
      $stmt->execute([':u' => $user]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($row && password_verify($pass, (string)$row['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$row['id'];
        $_SESSION['username'] = (string)$row['username'];
        $_SESSION['flash'] = 'Bienvenido, ' . (string)$row['username'] . ' 👋';
        $_SESSION['flash_type'] = 'success';
        go('index.php');
      }
      $errors['__global'] = 'Usuario o contraseña incorrectos.';
    } catch (Throwable $e) {
      $errors['__global'] = 'Error de autenticación.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Book Manager Pro</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
  <div class="login-card">
    <div class="login-title">
      <span class="ico">📚</span>
      <h2>Book Manager Pro</h2>
    </div>

    <?php if (!empty($errors['__global'])): ?>
      <div class="alert"><?= h($errors['__global']) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <div class="field">
        <input type="text" name="username" placeholder="Usuario" value="<?= h($user) ?>" required autocomplete="username">
        <?php if (!empty($errors['username'])): ?><div class="alert"><?= h($errors['username']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <input type="password" name="password" placeholder="Contraseña" required autocomplete="current-password">
        <?php if (!empty($errors['password'])): ?><div class="alert"><?= h($errors['password']) ?></div><?php endif; ?>
      </div>
      <button class="btn-primary" type="submit">Ingresar</button>
    </form>
  </div>
</body>
</html>
