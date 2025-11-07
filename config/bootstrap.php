<?php
// config/bootstrap.php
declare(strict_types=1);

// Zona horaria consistente
date_default_timezone_set('America/Costa_Rica');

// Endurecer cookies de sesión (antes de session_start)
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

// Si no hay sesión, iniciar
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Helper de flashes rápido (opcional)
function flash(?string $msg = null, string $type = 'info'): ?array {
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $type;
        return null;
    }
    if (isset($_SESSION['flash'])) {
        $out = ['msg' => $_SESSION['flash'], 'type' => $_SESSION['flash_type'] ?? 'info'];
        unset($_SESSION['flash'], $_SESSION['flash_type']);
        return $out;
    }
    return null;
}
