<?php
// auth_guard.php
declare(strict_types=1);

// Requiere que ya se haya incluido bootstrap (para la sesión)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash'] = 'Inicia sesión para continuar.';
    $_SESSION['flash_type'] = 'error';
    header('Location: login.php');
    exit;
}
