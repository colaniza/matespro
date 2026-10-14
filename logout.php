<?php
session_start();

// Destruir todas las variables de sesión
$_SESSION = array();

// Si se usan cookies de sesión, eliminarlas también
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión finalmente
session_destroy();

// Redirigir a la pantalla de login en la raíz
header("Location: ../index.php?logout=1");
exit();
?>