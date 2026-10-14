<?php
/**
 * ECOWATTS - API Backend / Handler de Peticiones AJAX
 * Maneja el carrito de consumo eléctrico, catálogo categorizado, cálculo de tarifas,
 * periodos de cálculo seleccionables (día, semana, mes, año) y gasto energético por hora.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario']) || !isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión no activa. Por favor vuelve a iniciar sesión.']);
    exit();
}

require_once __DIR__ . '/../conexion.php';

$usuario_id     = intval($_SESSION['id']);
$usuario_nombre = trim($_SESSION['usuario']);

// Validar que el usuario_id de la sesión realmente exista en la tabla 'usuarios'
// para prevenir violaciones de clave foránea (SQLSTATE 23000 error 1452)
$stmtCheckUser = $pdo->prepare("SELECT id FROM usuarios WHERE id = :uid LIMIT 1");
$stmtCheckUser->execute([':uid' => $usuario_id]);
$userExists = $stmtCheckUser->fetch();

if (!$userExists) {
    // Intentar auto-reparar la sesión buscando el nombre de usuario
    $stmtFindUser = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = :u LIMIT 1");
    $stmtFindUser->execute([':u' => $usuario_nombre]);
    $foundUser = $stmtFindUser->fetch();

    if ($foundUser) {
        $usuario_id = intval($foundUser['id']);
        $_SESSION['id'] = $usuario_id;
    } else {
        session_destroy();
        echo json_encode(['success' => false, 'error' => 'Tu sesión ha expirado o el usuario ya no existe. Por favor vuelve a iniciar sesión.']);
        exit();
    }
}

$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);

$action = isset($_POST['action']) ? trim($_POST['action']) : (isset($inputData['action']) ? trim($inputData['action']) : '');
$periodo = isset($_POST['periodo']) ? trim($_POST['periodo']) : (isset($inputData['periodo']) ? trim($inputData['periodo']) : '1_mes');

// Validar multiplicador del periodo de simulación
$diasMultiplicador = 30;
$nombrePeriodo = '1 Mes (30 días)';
switch ($periodo) {
    case '1_dia':
        $diasMultiplicador = 1;
        $nombrePeriodo = '1 Día';
        break;
    case '1_semana':
        $diasMultiplicador = 7;
        $nombrePeriodo = '1 Semana (7 días)';
        break;
    case '1_anio':
        $diasMultiplicador = 365;
        $nombrePeriodo = '1 Año (365 días)';
        break;
    case '1_mes':
    default:
        $diasMultiplicador = 30;
        $nombrePeriodo = '1 Mes (30 días)';
        $periodo = '1_mes';
        break;
}

function getCartPayload($pdo, $usuario_id, $periodo = '1_mes', $diasMultiplicador = 30, $nombrePeriodo = '1 Mes (30 días)') {
    // 1. Obtener la comuna del usuario
    $stmtUser = $pdo->prepare("
        SELECT u.comuna_id, u.comuna, c.id AS id_tarifa, c.region, c.comuna AS nombre_comuna, c.empresa, c.tarifa_kwh 
        FROM usuarios u 
        LEFT JOIN comunas_tarifas c ON u.comuna_id = c.id 
        WHERE u.id = :uid LIMIT 1
    ");
    $stmtUser->execute([':uid' => $usuario_id]);
    $userData = $stmtUser->fetch();

    $comunaActual = [
        'id'         => 1,
        'region'     => 'Atacama',
        'comuna'     => 'Vallenar',
        'empresa'    => 'CGE',
        'tarifa_kwh' => 145.50
    ];

    if ($userData && !empty($userData['tarifa_kwh'])) {
        $comunaActual = [
            'id'         => intval($userData['comuna_id']),
            'region'     => $userData['region'],
            'comuna'     => $userData['nombre_comuna'],
            'empresa'    => $userData['empresa'],
            'tarifa_kwh' => floatval($userData['tarifa_kwh'])
        ];
    } else {
        $stmtVall = $pdo->query("SELECT * FROM comunas_tarifas WHERE comuna = 'Vallenar' LIMIT 1");
        $vallData = $stmtVall->fetch();
        if ($vallData) {
            $comunaActual = [
                'id'         => intval($vallData['id']),
                'region'     => $vallData['region'],
                'comuna'     => $vallData['comuna'],
                'empresa'    => $vallData['empresa'],
                'tarifa_kwh' => floatval($vallData['tarifa_kwh'])
            ];
            $pdo->prepare("UPDATE usuarios SET comuna_id = :cid, comuna = 'Vallenar' WHERE id = :uid")
                ->execute([':cid' => $comunaActual['id'], ':uid' => $usuario_id]);
        }
    }

    $_SESSION['comuna_id'] = $comunaActual['id'];
    $_SESSION['comuna']    = $comunaActual['comuna'];

    // 2. Obtener lista completa de comunas
    $stmtComunas = $pdo->query("SELECT * FROM comunas_tarifas ORDER BY region ASC, comuna ASC");
    $comunasList = $stmtComunas->fetchAll();

    // 3. Catálogo maestro de dispositivos predefinidos
    $stmtPresets = $pdo->query("SELECT * FROM dispositivos_predefinidos ORDER BY nombre ASC");
    $presetsList = $stmtPresets->fetchAll();

    $presetMap = [];
    foreach ($presetsList as $p) {
        $presetMap[mb_strtolower(trim($p['nombre']))] = floatval($p['horas_estandar_dia']);
    }

    // 4. Ítems del carrito del usuario
    $stmtCart = $pdo->prepare("SELECT * FROM carrito_usuario WHERE usuario_id = :uid ORDER BY id DESC");
    $stmtCart->execute([':uid' => $usuario_id]);
    $rawItems = $stmtCart->fetchAll();

    $itemsCalculados  = [];
    $totalKwhDiario   = 0.0;
    $totalKwhPeriodo  = 0.0;
    $totalCostoHora   = 0.0;
    $totalCostoPeriodo = 0.0;
    $tarifaKwh        = $comunaActual['tarifa_kwh'];

    foreach ($rawItems as $item) {
        $id               = intval($item['id']);
        $nombre           = $item['nombre_dispositivo'];
        $watts            = intval($item['watts']);
        $cantidad         = max(1, intval($item['cantidad']));
        $horasUsoRaw      = $item['horas_uso_dia'];
        $esHorasEstimadas = false;

        if ($horasUsoRaw === null || floatval($horasUsoRaw) <= 0) {
            $nombreLower = mb_strtolower(trim($nombre));
            if (isset($presetMap[$nombreLower])) {
                $horasUso = $presetMap[$nombreLower];
            } else {
                $horasUso = 1.0;
            }
            $esHorasEstimadas = true;
        } else {
            $horasUso = floatval($horasUsoRaw);
        }

        // Fórmulas matemáticas:
        // Costo por hora (CLP/hora) = (Watts / 1000) * Tarifa_kWh * Cantidad
        // kWh diario = (Watts * Horas_uso / 1000) * Cantidad
        // kWh periodo = kWh_diario * Dias_multiplicador
        // Costo periodo = kWh_periodo * Tarifa_kWh
        $costoPorHora  = ($watts / 1000.0) * $tarifaKwh * $cantidad;
        $kwhDiario     = ($watts * $horasUso / 1000.0) * $cantidad;
        $kwhPeriodo    = $kwhDiario * $diasMultiplicador;
        $costoPeriodo  = $kwhPeriodo * $tarifaKwh;

        $totalKwhDiario    += $kwhDiario;
        $totalKwhPeriodo   += $kwhPeriodo;
        $totalCostoHora    += $costoPorHora;
        $totalCostoPeriodo += $costoPeriodo;

        $itemsCalculados[] = [
            'id'                 => $id,
            'nombre_dispositivo' => $nombre,
            'watts'              => $watts,
            'horas_uso_dia'      => floatval($item['horas_uso_dia']),
            'horas_efectivas'    => round($horasUso, 2),
            'es_estimado'        => $esHorasEstimadas,
            'cantidad'           => $cantidad,
            'costo_hora'         => round($costoPorHora, 2),
            'costo_hora_fmt'     => number_format(round($costoPorHora, 1), 1, ',', '.'),
            'kwh_diario'         => round($kwhDiario, 3),
            'kwh_periodo'        => round($kwhPeriodo, 2),
            'costo_periodo'      => round($costoPeriodo, 2),
            'costo_periodo_fmt'  => number_format(round($costoPeriodo), 0, ',', '.')
        ];
    }

    // Estructurar comunas por región para selector en cascada
    $comunasPorRegion = [];
    $regionesUnicas = [];
    foreach ($comunasList as $c) {
        $reg = $c['region'];
        if (!isset($comunasPorRegion[$reg])) {
            $comunasPorRegion[$reg] = [];
            $regionesUnicas[] = $reg;
        }
        $comunasPorRegion[$reg][] = $c;
    }

    return [
        'success'                  => true,
        'periodo_actual'           => $periodo,
        'nombre_periodo'           => $nombrePeriodo,
        'dias_multiplicador'       => $diasMultiplicador,
        'comuna_actual'            => $comunaActual,
        'regiones'                 => $regionesUnicas,
        'comunas_por_region'       => $comunasPorRegion,
        'comunas'                  => $comunasList,
        'dispositivos_predefinidos' => $presetsList,
        'items'                    => $itemsCalculados,
        'totales'                  => [
            'total_items'          => count($itemsCalculados),
            'costo_hora_total'     => round($totalCostoHora, 2),
            'costo_hora_total_fmt' => number_format(round($totalCostoHora, 1), 1, ',', '.'),
            'kwh_diario'           => round($totalKwhDiario, 3),
            'kwh_periodo'          => round($totalKwhPeriodo, 2),
            'costo_total_periodo'  => round($totalCostoPeriodo),
            'costo_total_fmt'      => number_format(round($totalCostoPeriodo), 0, ',', '.')
        ]
    ];
}

try {
    switch ($action) {
        case 'get_cart':
            echo json_encode(getCartPayload($pdo, $usuario_id, $periodo, $diasMultiplicador, $nombrePeriodo));
            break;

        case 'add_item':
            $nombre   = isset($_POST['nombre']) ? trim($_POST['nombre']) : (isset($inputData['nombre']) ? trim($inputData['nombre']) : '');
            $watts    = isset($_POST['watts']) ? intval($_POST['watts']) : (isset($inputData['watts']) ? intval($inputData['watts']) : 0);
            $cantidad = isset($_POST['cantidad']) ? intval($_POST['cantidad']) : (isset($inputData['cantidad']) ? intval($inputData['cantidad']) : 1);
            
            $horasRaw = null;
            if (isset($_POST['horas_uso']) && $_POST['horas_uso'] !== '') {
                $horasRaw = floatval($_POST['horas_uso']);
            } elseif (isset($inputData['horas_uso']) && $inputData['horas_uso'] !== '') {
                $horasRaw = floatval($inputData['horas_uso']);
            }

            if (empty($nombre)) {
                echo json_encode(['success' => false, 'error' => 'Debes seleccionar o ingresar un electrodoméstico.']);
                exit();
            }

            if ($watts <= 0) {
                $stmtP = $pdo->prepare("SELECT watts_promedio FROM dispositivos_predefinidos WHERE nombre = :nom LIMIT 1");
                $stmtP->execute([':nom' => $nombre]);
                $pRow = $stmtP->fetch();
                if ($pRow) {
                    $watts = intval($pRow['watts_promedio']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Por favor ingresa la potencia en Watts (ej. 200W).']);
                    exit();
                }
            }

            $cantidad = max(1, $cantidad);

            $stmtIns = $pdo->prepare("
                INSERT INTO carrito_usuario (usuario_id, nombre_dispositivo, watts, horas_uso_dia, cantidad)
                VALUES (:uid, :nombre, :watts, :horas, :cant)
            ");
            $stmtIns->execute([
                ':uid'    => $usuario_id,
                ':nombre' => $nombre,
                ':watts'  => $watts,
                ':horas'  => ($horasRaw !== null && $horasRaw > 0) ? $horasRaw : null,
                ':cant'   => $cantidad
            ]);

            echo json_encode(getCartPayload($pdo, $usuario_id, $periodo, $diasMultiplicador, $nombrePeriodo));
            break;

        case 'update_item':
            $itemId   = isset($_POST['item_id']) ? intval($_POST['item_id']) : (isset($inputData['item_id']) ? intval($inputData['item_id']) : 0);
            $watts    = isset($_POST['watts']) ? intval($_POST['watts']) : (isset($inputData['watts']) ? intval($inputData['watts']) : 0);
            $cantidad = isset($_POST['cantidad']) ? intval($_POST['cantidad']) : (isset($inputData['cantidad']) ? intval($inputData['cantidad']) : 1);
            
            $horasRaw = null;
            if (isset($_POST['horas_uso']) && $_POST['horas_uso'] !== '') {
                $horasRaw = floatval($_POST['horas_uso']);
            } elseif (isset($inputData['horas_uso']) && $inputData['horas_uso'] !== '') {
                $horasRaw = floatval($inputData['horas_uso']);
            }

            if ($itemId <= 0 || $watts <= 0) {
                echo json_encode(['success' => false, 'error' => 'Datos inválidos para actualizar el ítem.']);
                exit();
            }

            $cantidad = max(1, $cantidad);

            $stmtUpd = $pdo->prepare("
                UPDATE carrito_usuario 
                SET watts = :watts, horas_uso_dia = :horas, cantidad = :cant 
                WHERE id = :id AND usuario_id = :uid
            ");
            $stmtUpd->execute([
                ':watts' => $watts,
                ':horas' => ($horasRaw !== null && $horasRaw > 0) ? $horasRaw : null,
                ':cant'  => $cantidad,
                ':id'    => $itemId,
                ':uid'   => $usuario_id
            ]);

            echo json_encode(getCartPayload($pdo, $usuario_id, $periodo, $diasMultiplicador, $nombrePeriodo));
            break;

        case 'delete_item':
            $itemId = isset($_POST['item_id']) ? intval($_POST['item_id']) : (isset($inputData['item_id']) ? intval($inputData['item_id']) : 0);
            if ($itemId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID de ítem no válido.']);
                exit();
            }

            $stmtDel = $pdo->prepare("DELETE FROM carrito_usuario WHERE id = :id AND usuario_id = :uid");
            $stmtDel->execute([':id' => $itemId, ':uid' => $usuario_id]);

            echo json_encode(getCartPayload($pdo, $usuario_id, $periodo, $diasMultiplicador, $nombrePeriodo));
            break;

        case 'clear_cart':
            $stmtClr = $pdo->prepare("DELETE FROM carrito_usuario WHERE usuario_id = :uid");
            $stmtClr->execute([':uid' => $usuario_id]);

            echo json_encode(getCartPayload($pdo, $usuario_id, $periodo, $diasMultiplicador, $nombrePeriodo));
            break;

        case 'update_comuna':
            $comunaId = isset($_POST['comuna_id']) ? intval($_POST['comuna_id']) : (isset($inputData['comuna_id']) ? intval($inputData['comuna_id']) : 0);
            if ($comunaId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Comuna no válida.']);
                exit();
            }

            $stmtCom = $pdo->prepare("SELECT * FROM comunas_tarifas WHERE id = :cid LIMIT 1");
            $stmtCom->execute([':cid' => $comunaId]);
            $comData = $stmtCom->fetch();

            if ($comData) {
                $stmtUserUpd = $pdo->prepare("UPDATE usuarios SET comuna_id = :cid, comuna = :comuna WHERE id = :uid");
                $stmtUserUpd->execute([
                    ':cid'    => $comData['id'],
                    ':comuna' => $comData['comuna'],
                    ':uid'    => $usuario_id
                ]);

                $_SESSION['comuna_id'] = $comData['id'];
                $_SESSION['comuna']    = $comData['comuna'];
            }

            echo json_encode(getCartPayload($pdo, $usuario_id, $periodo, $diasMultiplicador, $nombrePeriodo));
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida o no especificada.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error en el servidor: ' . $e->getMessage()]);
}
?>
