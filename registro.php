<?php
session_start();

// Si ya tiene sesión activa, redirigir directo al sistema
if (isset($_SESSION['usuario'])) {
    header("Location: sistema/index.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

$error = '';
$mensaje_exito = '';

// Obtener lista de comunas registradas en la base de datos
$listaComunas = [];
$listaRegiones = [];
try {
    $stmtC = $pdo->query("SELECT id, region, comuna, empresa, tarifa_kwh FROM comunas_tarifas ORDER BY region ASC, comuna ASC");
    $listaComunas = $stmtC->fetchAll();
    // Extraer regiones únicas manteniendo el orden
    foreach ($listaComunas as $c) {
        if (!in_array($c['region'], $listaRegiones)) {
            $listaRegiones[] = $c['region'];
        }
    }
} catch (Exception $e) {
    // Fallback si la tabla aún no contiene datos
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre       = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $apellido     = isset($_POST['apellido']) ? trim($_POST['apellido']) : '';
    $usuario      = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $pass         = isset($_POST['pass']) ? trim($_POST['pass']) : '';
    $confirm_pass = isset($_POST['confirm_pass']) ? trim($_POST['confirm_pass']) : '';
    $comuna_id    = isset($_POST['comuna_id']) ? intval($_POST['comuna_id']) : 1;

    // Buscar el nombre de la comuna seleccionada
    $comunaNombre = 'Vallenar';
    foreach ($listaComunas as $c) {
        if (intval($c['id']) === $comuna_id) {
            $comunaNombre = $c['comuna'];
            break;
        }
    }

    if (empty($nombre) || empty($apellido) || empty($usuario) || empty($pass) || empty($confirm_pass)) {
        $error = 'Por favor completa todos los campos del formulario, incluyendo tu Nombre y Apellido.';
    } elseif ($pass !== $confirm_pass) {
        $error = 'Las contraseñas no coinciden. Por favor verifícalas.';
    } elseif (strlen($pass) < 4) {
        $error = 'La contraseña debe tener un mínimo de 4 caracteres.';
    } else {
        try {
            // Verificar si el usuario ya existe en la base de datos
            $stmt = $pdo->prepare("SELECT id, nombre, apellido, usuario, pass, perfil, comuna_id, comuna FROM usuarios WHERE usuario = :usuario LIMIT 1");
            $stmt->execute([':usuario' => $usuario]);
            $user = $stmt->fetch();

            if ($user) {
                // Si el usuario existe, verificar si la contraseña coincide
                $passValida = false;
                if (password_verify($pass, $user['pass'])) {
                    $passValida = true;
                } elseif ($user['pass'] === $pass) {
                    $passValida = true;
                } elseif (md5($pass) === $user['pass']) {
                    $passValida = true;
                }

                if ($passValida) {
                    // Actualizar nombre y apellido si no los tenía
                    if (!empty($nombre) && !empty($apellido)) {
                        $upd = $pdo->prepare("UPDATE usuarios SET nombre = :nom, apellido = :ape WHERE id = :id");
                        $upd->execute([':nom' => $nombre, ':ape' => $apellido, ':id' => $user['id']]);
                    }

                    $_SESSION['id']        = $user['id'];
                    $_SESSION['usuario']   = $user['usuario'];
                    $_SESSION['nombre']    = !empty($user['nombre']) ? $user['nombre'] : $nombre;
                    $_SESSION['apellido']  = !empty($user['apellido']) ? $user['apellido'] : $apellido;
                    $_SESSION['perfil']    = !empty($user['perfil']) ? $user['perfil'] : 'cliente';
                    $_SESSION['comuna_id'] = !empty($user['comuna_id']) ? $user['comuna_id'] : $comuna_id;
                    $_SESSION['comuna']    = !empty($user['comuna']) ? $user['comuna'] : $comunaNombre;

                    header("Location: sistema/index.php");
                    exit();
                } else {
                    $error = 'El nombre de usuario ya está registrado con otra contraseña. Por favor inicia sesión.';
                }
            } else {
                // Guardar usuario en la base de datos con nombre, apellido, perfil 'cliente' y comuna_id
                $hashPass = password_hash($pass, PASSWORD_DEFAULT);
                $perfil   = 'cliente';

                $insertStmt = $pdo->prepare("INSERT INTO usuarios (nombre, apellido, usuario, pass, perfil, comuna_id, comuna) VALUES (:nom, :ape, :usuario, :pass, :perfil, :cid, :comuna)");
                $insertStmt->execute([
                    ':nom'     => $nombre,
                    ':ape'     => $apellido,
                    ':usuario' => $usuario,
                    ':pass'    => $hashPass,
                    ':perfil'  => $perfil,
                    ':cid'     => $comuna_id,
                    ':comuna'  => $comunaNombre
                ]);

                $nuevoId = $pdo->lastInsertId();

                // Iniciar sesión e ingresar al sistema
                $_SESSION['id']        = $nuevoId;
                $_SESSION['usuario']   = $usuario;
                $_SESSION['nombre']    = $nombre;
                $_SESSION['apellido']  = $apellido;
                $_SESSION['perfil']    = $perfil;
                $_SESSION['comuna_id'] = $comuna_id;
                $_SESSION['comuna']    = $comunaNombre;

                header("Location: sistema/index.php?bienvenida=1");
                exit();
            }
        } catch (PDOException $e) {
            $error = 'Error en el servidor al procesar el registro: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Ecowatts - Registro de Usuario</title>
    <link rel="stylesheet" href="sistema/css/auth.css?v=<?php echo time(); ?>">
</head>
<body>

<!-- Botón flotante de tema claro/oscuro -->
<button id="btn-theme-toggle" class="btn-theme-toggle" title="Cambiar tema">☀️</button>

<div class="auth-container">
    <div class="auth-header">
        <div class="brand-badge" title="Ecowatts Registro">
            <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="8.5" cy="7" r="4"></circle>
                <line x1="20" y1="8" x2="20" y2="14"></line>
                <line x1="23" y1="11" x2="17" y2="11"></line>
            </svg>
        </div>
        <h1>Crear <span>Cuenta</span></h1>
        <p>Únete a la red inteligente de Ecowatts</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <form id="form-registro" action="registro.php" method="POST" class="auth-form" autocomplete="off">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group">
                <label for="nombre">Nombre</label>
                <div class="input-wrapper">
                    <span class="input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <input 
                        type="text" 
                        id="nombre" 
                        name="nombre" 
                        class="form-control" 
                        placeholder="Tu Nombre" 
                        value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>" 
                        required 
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="apellido">Apellido</label>
                <div class="input-wrapper">
                    <span class="input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <input 
                        type="text" 
                        id="apellido" 
                        name="apellido" 
                        class="form-control" 
                        placeholder="Tu Apellido" 
                        value="<?php echo isset($_POST['apellido']) ? htmlspecialchars($_POST['apellido']) : ''; ?>" 
                        required
                    >
                </div>
            </div>
        </div>

        <div class="form-group">
            <label for="usuario">Nombre de Usuario (para iniciar sesión)</label>
            <div class="input-wrapper">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </span>
                <input 
                    type="text" 
                    id="usuario" 
                    name="usuario" 
                    class="form-control" 
                    placeholder="Elige tu nombre de usuario" 
                    value="<?php echo isset($_POST['usuario']) ? htmlspecialchars($_POST['usuario']) : ''; ?>" 
                    required
                >
            </div>
        </div>

        <div class="form-group">
            <label for="pass">Contraseña</label>
            <div class="input-wrapper">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </span>
                <input 
                    type="password" 
                    id="pass" 
                    name="pass" 
                    class="form-control" 
                    placeholder="Mínimo 4 caracteres" 
                    required
                >
                <button type="button" class="toggle-password" data-target="pass" title="Mostrar contraseña">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_pass">Confirmar Contraseña</label>
            <div class="input-wrapper">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                </span>
                <input 
                    type="password" 
                    id="confirm_pass" 
                    name="confirm_pass" 
                    class="form-control" 
                    placeholder="Repite tu contraseña" 
                    required
                >
                <button type="button" class="toggle-password" data-target="confirm_pass" title="Mostrar contraseña">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label>Región y Comuna (Chile)</label>
            <div class="region-comuna-group">
                <!-- SELECT REGIÓN -->
                <div>
                    <select id="select-region" class="form-control" required>
                        <option value="">📍 Selecciona tu región</option>
                        <?php if (!empty($listaRegiones)): ?>
                            <?php foreach ($listaRegiones as $region): ?>
                                <option value="<?php echo htmlspecialchars($region); ?>">
                                    <?php echo htmlspecialchars($region); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="Atacama">Atacama</option>
                            <option value="Metropolitana">Metropolitana</option>
                            <option value="Valparaíso">Valparaíso</option>
                            <option value="Coquimbo">Coquimbo</option>
                            <option value="Biobío">Biobío</option>
                            <option value="Antofagasta">Antofagasta</option>
                            <option value="La Araucanía">La Araucanía</option>
                            <option value="Los Lagos">Los Lagos</option>
                            <option value="Magallanes">Magallanes</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- SELECT COMUNA (se filtra por JS según la región elegida) -->
                <div>
                    <select id="comuna_id" name="comuna_id" class="form-control" required disabled>
                        <option value="">🏘️ Primero elige región</option>
                        <?php if (!empty($listaComunas)): ?>
                            <?php foreach ($listaComunas as $c): ?>
                                <option
                                    value="<?php echo $c['id']; ?>"
                                    data-region="<?php echo htmlspecialchars($c['region']); ?>"
                                    <?php echo ($c['comuna'] === 'Vallenar') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['comuna'] . ' — $' . number_format($c['tarifa_kwh'], 2, ',', '.') . '/kWh (' . $c['empresa'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="1" data-region="Atacama">Vallenar — $145,50/kWh (CGE)</option>
                            <option value="2" data-region="Atacama">Alto del Carmen — $145,50/kWh (CGE)</option>
                            <option value="3" data-region="Atacama">Freirina — $145,50/kWh (CGE)</option>
                            <option value="4" data-region="Atacama">Huasco — $145,50/kWh (CGE)</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
        </div>


        <div class="info-note">
            <span class="info-note-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
            </span>
            <div>
                <strong>Persistencia Ecowatts:</strong> Tus equipos y comuna seleccionada se guardarán automáticamente en tu cuenta. Podrás cambiar la tarifa en cualquier momento.
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <span>Registrarse e Ingresar</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
        </button>
    </form>

    <div class="auth-divider">¿Ya estás registrado?</div>

    <a href="index.php" class="btn-secondary-action">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
        <span>Volver al Inicio de Sesión</span>
    </a>

    <div class="auth-footer">
        &copy; <?php echo date('Y'); ?> Ecowatts. Todos los derechos reservados.
    </div>
</div>

<script src="sistema/js/auth.js"></script>
</body>
</html>