<?php
session_start();

// Control de acceso: Verificar que el usuario haya iniciado sesión
if (!isset($_SESSION['usuario']) || !isset($_SESSION['id'])) {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . '/../conexion.php';

$usuario_id     = intval($_SESSION['id']);
$usuario_nombre = trim($_SESSION['usuario']);

// Validar y auto-corregir ID de usuario en sesión si la base de datos se actualizó
$stmtCheckUser = $pdo->prepare("SELECT id, nombre, apellido, usuario FROM usuarios WHERE id = :uid LIMIT 1");
$stmtCheckUser->execute([':uid' => $usuario_id]);
$userRow = $stmtCheckUser->fetch();

if (!$userRow) {
    $stmtFindUser = $pdo->prepare("SELECT id, nombre, apellido, usuario FROM usuarios WHERE usuario = :u LIMIT 1");
    $stmtFindUser->execute([':u' => $usuario_nombre]);
    $userRow = $stmtFindUser->fetch();

    if ($userRow) {
        $usuario_id = intval($userRow['id']);
        $_SESSION['id'] = $usuario_id;
    } else {
        session_destroy();
        header("Location: ../index.php?logout=1");
        exit();
    }
}

// Nombre formal: Nombre + Apellido si están disponibles, sino el usuario
$nombreCompleto = '';
if (!empty($userRow['nombre'])) {
    $nombreCompleto = trim($userRow['nombre'] . ' ' . (!empty($userRow['apellido']) ? $userRow['apellido'] : ''));
}
$nombreDisplay = !empty($nombreCompleto) ? htmlspecialchars($nombreCompleto) : htmlspecialchars($usuario_nombre);
$usuario       = $nombreDisplay;
$perfil        = isset($_SESSION['perfil']) ? htmlspecialchars($_SESSION['perfil']) : 'cliente';
$comuna        = isset($_SESSION['comuna']) ? htmlspecialchars($_SESSION['comuna']) : 'Vallenar';
$inicial       = strtoupper(mb_substr($nombreDisplay, 0, 1));
$primerNombre  = !empty($userRow['nombre']) ? htmlspecialchars($userRow['nombre']) : $nombreDisplay;

// Cargar comunas para el selector
$comunasLista = [];
$regionesLista = [];
$comunasPorRegion = [];
try {
    $stmtC = $pdo->query("SELECT * FROM comunas_tarifas ORDER BY region ASC, comuna ASC");
    $comunasLista = $stmtC->fetchAll();
    foreach ($comunasLista as $c) {
        $reg = $c['region'];
        if (!in_array($reg, $regionesLista)) {
            $regionesLista[] = $reg;
        }
        if (!isset($comunasPorRegion[$reg])) {
            $comunasPorRegion[$reg] = [];
        }
        $comunasPorRegion[$reg][] = $c;
    }
} catch (Exception $e) {}

// Obtener comuna actual del usuario
$comunaUser = null;
try {
    $stmtU = $pdo->prepare("
        SELECT u.comuna_id, u.comuna, c.region, c.empresa, c.tarifa_kwh 
        FROM usuarios u 
        LEFT JOIN comunas_tarifas c ON u.comuna_id = c.id 
        WHERE u.id = :uid LIMIT 1
    ");
    $stmtU->execute([':uid' => $usuario_id]);
    $comunaUser = $stmtU->fetch();
} catch (Exception $e) {}

$tarifaActual = ($comunaUser && isset($comunaUser['tarifa_kwh'])) ? floatval($comunaUser['tarifa_kwh']) : 145.50;
$empresaActual = ($comunaUser && isset($comunaUser['empresa'])) ? $comunaUser['empresa'] : 'CGE';
$comunaNombreActual = ($comunaUser && isset($comunaUser['comuna'])) ? $comunaUser['comuna'] : $comuna;
$comunaIdActual = ($comunaUser && isset($comunaUser['comuna_id'])) ? intval($comunaUser['comuna_id']) : 1;
$regionActual = ($comunaUser && isset($comunaUser['region'])) ? $comunaUser['region'] : 'Atacama';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecowatts — Gestión de Consumo Eléctrico</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Aplicar el tema antes de pintar para evitar parpadeo -->
    <script>
        (function () {
            var t = localStorage.getItem('ecowatts-theme');
            if (!t) t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
            if (t === 'light') document.documentElement.classList.add('light-preload');
        })();
    </script>
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">
    <script src="js/chart.umd.min.js"></script>
</head>
<body>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<!-- =========================================================
     MENÚ LATERAL
     ========================================================= -->
<aside id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <a href="index.php" class="brand">
            <span class="brand-mark">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/></svg>
            </span>
            <span class="brand-text">Ecowatts</span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Recorrido</div>

        <button type="button" class="nav-item active" id="nav-btn-intro">
            <svg class="nav-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
            <span class="nav-label">Presentación</span>
        </button>

        <button type="button" class="nav-item" id="nav-btn-calc">
            <svg class="nav-icon" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 12h.01M12 12h.01M15 12h.01M9 16h.01M12 16h.01M15 16h.01"/></svg>
            <span class="nav-label">Calculadora</span>
        </button>

        <button type="button" class="nav-item" id="nav-btn-cart">
            <svg class="nav-icon" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
            <span class="nav-label">Resumen y total</span>
            <span id="nav-cart-badge" class="cart-counter-badge" style="display: none;">0</span>
        </button>

        <div class="nav-section-title">Análisis</div>

        <button type="button" class="nav-item" id="nav-btn-analytics">
            <svg class="nav-icon" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
            <span class="nav-label">Gráficos</span>
        </button>

        <button type="button" class="nav-item" id="nav-btn-ai">
            <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
            <span class="nav-label">Asistente</span>
        </button>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile-mini">
            <div class="user-avatar"><?php echo $inicial; ?></div>
            <div class="user-info">
                <div class="user-name"><?php echo $usuario; ?></div>
                <div class="user-role" id="mini-comuna-label"><?php echo htmlspecialchars($comunaNombreActual); ?></div>
            </div>
            <a href="logout.php" class="btn-logout-mini" title="Cerrar sesión">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </a>
        </div>
    </div>
</aside>

<!-- =========================================================
     ÁREA PRINCIPAL
     ========================================================= -->
<div class="main-wrapper">
    <header class="topbar">
        <div class="topbar-left">
            <button id="toggleSidebar" class="toggle-sidebar-btn" title="Menú">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <div class="topbar-title">
                <span class="eyebrow">Panel de consumo</span>
                <h2 id="topbar-view-title">Presentación</h2>
            </div>
        </div>

        <div class="topbar-right">
            <div class="comuna-cascade-container" title="Ubicación y tarifa">
                <label class="field-inline">
                    <span class="field-inline-label">Región</span>
                    <select id="select-region-topbar" class="select-inline">
                        <?php foreach ($regionesLista as $reg): ?>
                            <option value="<?php echo htmlspecialchars($reg); ?>" <?php echo ($reg === $regionActual) ? 'selected' : ''; ?>><?php echo htmlspecialchars($reg); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="field-divider"></span>
                <label class="field-inline">
                    <span class="field-inline-label">Comuna</span>
                    <select id="select-comuna-topbar" class="select-inline">
                        <?php
                        $comunasDeRegionActual = isset($comunasPorRegion[$regionActual]) ? $comunasPorRegion[$regionActual] : $comunasLista;
                        foreach ($comunasDeRegionActual as $c):
                        ?>
                            <option value="<?php echo $c['id']; ?>" data-region="<?php echo htmlspecialchars($c['region']); ?>" <?php echo (intval($c['id']) === $comunaIdActual) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['comuna'] . ' · $' . number_format($c['tarifa_kwh'], 1, ',', '.') . '/kWh'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <button id="btn-theme-topbar" class="icon-btn" title="Cambiar tema" type="button"></button>
        </div>
    </header>

    <main class="content-area">

        <!-- Progreso -->
        <nav class="progress-stepper" aria-label="Progreso">
            <div class="step-item active" id="stepper-step-1"><span class="step-badge">I</span><span>Presentación</span></div>
            <span class="step-arrow"></span>
            <div class="step-item" id="stepper-step-2"><span class="step-badge">II</span><span>Calculadora</span></div>
            <span class="step-arrow"></span>
            <div class="step-item" id="stepper-step-3"><span class="step-badge">III</span><span>Resumen</span></div>
            <span class="step-arrow"></span>
            <div class="step-item" id="stepper-step-4"><span class="step-badge">IV</span><span>Gráficos</span></div>
            <span class="step-arrow"></span>
            <div class="step-item" id="stepper-step-5"><span class="step-badge">V</span><span>Asistente</span></div>
        </nav>

        <div class="views-stage">

        <!-- =====================================================
             VISTA 1: PRESENTACIÓN
             ===================================================== -->
        <section id="view-intro" class="view-section" data-title="Presentación">
            <div class="hero">
                <span class="eyebrow reveal" style="--i:0">Bienvenido</span>
                <h1 class="display reveal" style="--i:1"><?php echo $primerNombre; ?>, conozca el verdadero costo de su energía.</h1>
                <p class="lead reveal" style="--i:2">
                    Ecowatts calcula con precisión cuánto le cuesta, en pesos chilenos, cada artefacto eléctrico de su hogar,
                    según la tarifa oficial vigente en su comuna.
                </p>

                <div class="feature-row">
                    <article class="feature reveal" style="--i:3">
                        <span class="feature-num">01</span>
                        <h4>Seleccione sus equipos</h4>
                        <p>Elija entre un catálogo de artefactos frecuentes o registre los suyos con su potencia exacta.</p>
                    </article>
                    <article class="feature reveal" style="--i:4">
                        <span class="feature-num">02</span>
                        <h4>Revise el costo por hora</h4>
                        <p>Conozca el valor real de mantener encendido cada equipo, hora a hora.</p>
                    </article>
                    <article class="feature reveal" style="--i:5">
                        <span class="feature-num">03</span>
                        <h4>Proyecte su cuenta</h4>
                        <p>Simule su gasto diario, semanal, mensual o anual y descubra su potencial de ahorro.</p>
                    </article>
                </div>

                <!-- Transcriptor / Teleprompter con efecto Karaoke interactivo -->
                <div id="hero-karaoke-box" class="hero-karaoke-box" style="display: none;" aria-live="polite">
                    <div class="karaoke-badge">
                        <span class="karaoke-dot"></span>
                        <span>Asistente en vivo</span>
                    </div>
                    <div id="hero-karaoke-text" class="hero-karaoke-text"></div>
                </div>

                <div class="hero-actions reveal" style="--i:6">
                    <button type="button" id="btn-goto-calculator" class="btn btn-primary">
                        <span>Comenzar con la calculadora</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>

                    <button type="button" id="btn-voice-intro" class="btn btn-voice-narrator" title="Escuchar explicación del programa con la voz de la asistente de IA">
                        <span class="voice-wave-anim" aria-hidden="true">
                            <span></span><span></span><span></span><span></span>
                        </span>
                        <svg class="voice-icon-speaker" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
                        <span id="btn-voice-intro-label">Escuchar explicación</span>
                    </button>

                    <span class="hero-note">Tarifa configurada para <strong><?php echo htmlspecialchars($comunaNombreActual); ?></strong></span>
                </div>
            </div>
        </section>

        <!-- =====================================================
             VISTA 2: CALCULADORA
             ===================================================== -->
        <section id="view-calculator" class="view-section" data-title="Calculadora" style="display: none;">
            <div class="panel">
                <header class="panel-header reveal" style="--i:0">
                    <div>
                        <span class="eyebrow">Paso II</span>
                        <h2 class="panel-title">Calculadora de artefactos</h2>
                        <p class="panel-sub">Elija un artefacto, confirme las horas de uso y añádalo a su resumen.</p>
                    </div>
                    <button type="button" class="btn btn-ghost" id="btn-back-to-intro">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                        <span>Volver</span>
                    </button>
                </header>

                <div class="panel-body">
                    <div class="category-tabs-clean reveal" style="--i:1">
                        <button type="button" class="category-clean-btn active" data-category="cocina">Cocina</button>
                        <button type="button" class="category-clean-btn" data-category="clima">Climatización</button>
                        <button type="button" class="category-clean-btn" data-category="tech">Tecnología</button>
                        <button type="button" class="category-clean-btn" data-category="hogar">Hogar</button>
                    </div>

                    <div class="appliance-clean-grid reveal" style="--i:2" id="appliance-grid"></div>

                    <form id="form-add-device" class="clean-form reveal" style="--i:3">
                        <div class="form-row-pair">
                            <div class="form-group-clean">
                                <label for="device-name">Artefacto</label>
                                <input type="text" id="device-name" class="input-clean" placeholder="Seleccione arriba o escriba un nombre" required>
                            </div>
                            <div class="form-group-clean">
                                <label for="device-watts">Potencia (W)</label>
                                <input type="number" id="device-watts" class="input-clean" placeholder="200" min="1" max="10000" required>
                            </div>
                        </div>

                        <div class="form-row-pair">
                            <div class="form-group-clean">
                                <label for="device-hours-select">Horas de uso diario</label>
                                <select id="device-hours-select" class="input-clean">
                                    <option value="">Uso habitual recomendado</option>
                                    <option value="0.5">30 minutos</option>
                                    <option value="1">1 hora</option>
                                    <option value="2">2 horas</option>
                                    <option value="3">3 horas</option>
                                    <option value="4">4 horas</option>
                                    <option value="5">5 horas</option>
                                    <option value="8">8 horas</option>
                                    <option value="12">12 horas</option>
                                    <option value="24">24 horas continuas</option>
                                </select>
                            </div>
                            <div class="form-group-clean">
                                <label for="device-qty">Cantidad</label>
                                <input type="number" id="device-qty" class="input-clean" value="1" min="1" max="50" required>
                            </div>
                        </div>

                        <button type="submit" id="btn-add-item" class="btn btn-primary btn-block">
                            <span>Añadir al resumen</span>
                        </button>
                    </form>

                    <div class="calc-bottom-action-bar reveal" style="--i:4">
                        <div class="saved-count-pill">
                            Ha registrado <strong id="calc-saved-badge">0 artefactos</strong>
                        </div>
                        <button type="button" id="btn-goto-cart" class="btn btn-outline">
                            <span>Ver resumen y total</span>
                            <span id="btn-cart-badge" class="cart-counter-badge" style="display: none;">0</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- =====================================================
             VISTA 3: RESUMEN Y TOTAL
             ===================================================== -->
        <section id="view-cart" class="view-section" data-title="Resumen y total" style="display: none;">
            <div class="panel">
                <header class="panel-header reveal" style="--i:0">
                    <div>
                        <span class="eyebrow">Paso III</span>
                        <h2 class="panel-title">Resumen de consumo</h2>
                        <p class="panel-sub">Detalle por hora, por día y total a pagar de sus artefactos.</p>
                    </div>
                    <div class="cart-toolbar">
                        <select id="select-periodo-cart" class="select-period-clean" title="Período de cálculo">
                            <option value="1_mes" selected>Período: 1 mes</option>
                            <option value="1_semana">Período: 1 semana</option>
                            <option value="1_dia">Período: 1 día</option>
                            <option value="1_anio">Período: 1 año</option>
                        </select>
                        <button id="btn-clear-cart" class="btn btn-ghost btn-danger-text" title="Vaciar lista" type="button">Vaciar</button>
                    </div>
                </header>

                <div class="cart-big-summary-box reveal" style="--i:1">
                    <div class="summary-main-val">
                        <div class="label">Total a pagar</div>
                        <div class="amount" id="total-banner-amount">$ 0 CLP</div>
                        <div class="period" id="total-banner-period-name">1 Mes (30 días)</div>
                    </div>
                    <div class="summary-kpi-grid">
                        <div class="kpi-stat-chip">
                            <span class="lbl">Costo por hora activa</span>
                            <span class="val" id="kpi-costo-hora">$ 0 CLP/h</span>
                        </div>
                        <div class="kpi-stat-chip">
                            <span class="lbl">Consumo diario</span>
                            <span class="val" id="kpi-kwh-diario">0.00 kWh/día</span>
                        </div>
                        <div class="kpi-stat-chip">
                            <span class="lbl">Consumo del período</span>
                            <span class="val" id="kpi-kwh-periodo">0.0 kWh</span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive-clean reveal" style="--i:2">
                    <table class="table-clean" id="cart-table">
                        <thead>
                            <tr>
                                <th>Artefacto</th>
                                <th>Potencia</th>
                                <th>Horas/día</th>
                                <th>Cant.</th>
                                <th>Costo / hora</th>
                                <th>kWh / día</th>
                                <th id="th-costo-periodo">Total</th>
                                <th style="text-align: center;"></th>
                            </tr>
                        </thead>
                        <tbody id="cart-items-tbody"></tbody>
                    </table>
                </div>

                <div id="cart-empty-msg" class="empty-state-clean" style="display: none;">
                    <h4>Aún no hay artefactos registrados</h4>
                    <p>Añada equipos desde la calculadora para ver su resumen de gastos.</p>
                </div>

                <div class="savings-recommendation-card reveal" style="--i:3; display: none;" id="savings-recommendation-container">
                    <div class="savings-header">
                        <div class="savings-title-box">
                            <span class="eyebrow">Eficiencia energética</span>
                            <h3>Comparativa de ahorro</h3>
                            <p>El efecto de hábitos de consumo más eficientes sobre su factura.</p>
                        </div>
                    </div>

                    <div class="comparison-boxes-grid">
                        <div class="compare-box actual">
                            <div class="comp-lbl">Gasto actual estimado</div>
                            <div class="comp-val" id="comp-val-actual">$ 0 CLP</div>
                            <div class="comp-desc">Proyección con sus hábitos y artefactos actuales.</div>
                        </div>
                        <div class="compare-box saving">
                            <div class="comp-lbl">Ahorro posible</div>
                            <div class="comp-val" id="comp-val-saving">-$ 0 CLP</div>
                            <div class="comp-desc">Aplicando hábitos de uso eficiente (aprox. 25%).</div>
                        </div>
                        <div class="compare-box target">
                            <div class="comp-lbl">Total optimizado</div>
                            <div class="comp-val" id="comp-val-target">$ 0 CLP</div>
                            <div class="comp-desc">Lo que podría pagar optimizando su consumo.</div>
                        </div>
                    </div>

                    <h4 class="tips-title">Recomendaciones</h4>
                    <div class="tips-list-clean" id="tips-dynamic-list">
                        <div class="tip-pill"><span class="tip-icon">i</span><div><strong>Consumo en reposo.</strong> Desconecte cargadores y equipos en espera durante la noche; evita hasta un 10% de gasto invisible.</div></div>
                        <div class="tip-pill"><span class="tip-icon">ii</span><div><strong>Refrigeración.</strong> Mantenga el refrigerador a 4 °C y evite abrirlo con frecuencia o introducir alimentos calientes.</div></div>
                        <div class="tip-pill"><span class="tip-icon">iii</span><div><strong>Hervidor.</strong> Caliente solo el agua necesaria y conserve el resto en un termo.</div></div>
                        <div class="tip-pill"><span class="tip-icon">iv</span><div><strong>Iluminación LED.</strong> Reemplazar ampolletas tradicionales reduce el gasto en iluminación hasta un 85%.</div></div>
                    </div>
                </div>

                <div class="cart-bottom-actions">
                    <button type="button" id="btn-back-to-calculator" class="btn btn-ghost">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                        <span>Añadir más artefactos</span>
                    </button>
                    <button type="button" id="btn-goto-analytics-from-cart" class="btn btn-primary">
                        <span>Ver gráficos comparativos</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>
                </div>
            </div>
        </section>

        <!-- =====================================================
             VISTA 4: GRÁFICOS
             ===================================================== -->
        <section id="view-analytics" class="view-section" data-title="Gráficos" style="display: none;">
            <div class="panel">
                <header class="panel-header reveal" style="--i:0">
                    <div>
                        <span class="eyebrow">Paso IV</span>
                        <h2 class="panel-title">Análisis de consumo</h2>
                        <p class="panel-sub">Evolución mensual y comparación entre gasto actual y ahorro proyectado.</p>
                    </div>
                    <span class="analytics-pill-badge" id="badge-analytics-comuna"><?php echo htmlspecialchars($comunaNombreActual); ?> · $<?php echo number_format($tarifaActual, 1, ',', '.'); ?>/kWh</span>
                </header>

                <div class="analytics-grid">
                    <div class="chart-card reveal" style="--i:1">
                        <div class="chart-card-header">
                            <span class="chart-index">01</span>
                            <div>
                                <h3>Consumo mensual comparativo</h3>
                                <p>Tendencia de energía (kWh) y gasto estimado mes a mes.</p>
                            </div>
                        </div>
                        <div class="chart-canvas-container"><canvas id="chart-consumo-mensual"></canvas></div>
                        <div class="chart-card-footer">
                            <div class="chart-stat-chip"><span class="lbl">Consumo mensual base</span><span class="val" id="chart-lbl-kwh-mes">0.0 kWh</span></div>
                            <div class="chart-stat-chip"><span class="lbl">Gasto mensual estimado</span><span class="val" id="chart-lbl-clp-mes">$ 0 CLP</span></div>
                        </div>
                    </div>

                    <div class="chart-card reveal" style="--i:2">
                        <div class="chart-card-header">
                            <span class="chart-index">02</span>
                            <div>
                                <h3>Gasto actual frente a ahorro</h3>
                                <p>Impacto económico de aplicar hábitos de eficiencia energética.</p>
                            </div>
                        </div>
                        <div class="chart-canvas-container"><canvas id="chart-gasto-ahorro"></canvas></div>
                        <div class="chart-card-footer">
                            <div class="chart-stat-chip red-tint"><span class="lbl">Gasto actual proyectado</span><span class="val" id="chart-lbl-gasto-actual">$ 0 CLP</span></div>
                            <div class="chart-stat-chip green-tint"><span class="lbl">Ahorro estimado (25%)</span><span class="val" id="chart-lbl-ahorro-posible">-$ 0 CLP</span></div>
                        </div>
                    </div>
                </div>

                <div class="cart-bottom-actions">
                    <button type="button" id="btn-back-to-cart-from-analytics" class="btn btn-ghost">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                        <span>Volver al resumen</span>
                    </button>
                    <button type="button" id="btn-goto-ai-from-analytics" class="btn btn-primary">
                        <span>Consultar al asistente</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>
                </div>
            </div>
        </section>

        <!-- =====================================================
             VISTA 5: ASISTENTE
             ===================================================== -->
        <section id="view-ai" class="view-section" data-title="Asistente" style="display: none;">
            <div class="panel">
                <header class="panel-header reveal" style="--i:0">
                    <div class="ai-header-row">
                        <span class="ai-monogram">E</span>
                        <div>
                            <span class="eyebrow">Paso V</span>
                            <h2 class="panel-title">Asistente Ecowatts</h2>
                            <p class="panel-sub">Resuelva dudas sobre la plataforma y reciba recomendaciones para optimizar su consumo.</p>
                        </div>
                    </div>
                </header>

                <div class="panel-body ai-view-body">
                    <div class="ai-quick-prompts reveal" style="--i:1">
                        <span class="ai-prompts-label">Consultas frecuentes</span>
                        <div class="ai-prompts-chips">
                            <button type="button" class="ai-chip-btn" data-query="¿De qué trata Ecowatts y cómo funciona el programa?">¿Qué hace Ecowatts?</button>
                            <button type="button" class="ai-chip-btn" data-query="¿Cómo puedo reducir mi cuenta de luz hasta un 30%?">Cómo reducir mi cuenta</button>
                            <button type="button" class="ai-chip-btn" data-query="¿Cuánto consume un refrigerador y cómo optimizar su gasto?">Consumo del refrigerador</button>
                            <button type="button" class="ai-chip-btn" data-query="¿Por qué el hervidor eléctrico gasta tanta electricidad?">El hervidor eléctrico</button>
                            <button type="button" class="ai-chip-btn" data-query="¿Cómo se calcula el costo en mi comuna de Chile?">Tarifas de mi comuna</button>
                        </div>
                    </div>

                    <div class="ai-chat-messages reveal" style="--i:2" id="ai-chat-messages-view">
                        <div class="ai-message assistant">
                            <div class="ai-msg-avatar">E</div>
                            <div class="ai-msg-bubble">
                                <p><strong>Buen día, <?php echo $primerNombre; ?>.</strong> Soy el asistente de Ecowatts.</p>
                                <p>Puedo explicarle el funcionamiento de la plataforma, cómo simular sus artefactos en <strong><?php echo htmlspecialchars($comunaNombreActual); ?></strong> ($<?php echo number_format($tarifaActual, 1, ',', '.'); ?>/kWh) y sugerirle formas de reducir su consumo. ¿En qué puedo ayudarle?</p>
                            </div>
                        </div>
                    </div>

                    <form id="ai-form-view" class="ai-chat-input-form reveal" style="--i:3">
                        <input type="text" id="ai-input-view" class="ai-chat-input" placeholder="Escriba su consulta…" required autocomplete="off">
                        <button type="submit" class="btn btn-primary">Enviar</button>
                    </form>
                </div>
            </div>
        </section>

        </div><!-- /views-stage -->
    </main>
</div>

<!-- =========================================================
     WIDGET FLOTANTE DEL ASISTENTE
     ========================================================= -->
<div class="ai-floating-widget" id="ai-floating-widget">
    <div class="ai-widget-window" id="ai-widget-window" style="display: none;">
        <div class="ai-widget-header">
            <div class="ai-widget-title-area">
                <span class="ai-widget-status-dot"></span>
                <strong>Asistente</strong>
                <span class="ai-widget-sub">Ecowatts</span>
            </div>
            <button type="button" class="ai-widget-close" id="btn-close-ai-widget" title="Cerrar">&times;</button>
        </div>

        <div class="ai-widget-messages" id="ai-widget-messages">
            <div class="ai-message assistant">
                <div class="ai-msg-avatar">E</div>
                <div class="ai-msg-bubble"><p>¿Tiene alguna duda sobre sus artefactos o el uso de la calculadora? Estoy a su disposición.</p></div>
            </div>
        </div>

        <div class="ai-widget-quick">
            <button type="button" class="ai-mini-chip" data-query="¿Cómo funciona Ecowatts?">Funcionamiento</button>
            <button type="button" class="ai-mini-chip" data-query="¿Cómo ahorrar 30% en luz?">Ahorro</button>
            <button type="button" class="ai-mini-chip" data-query="¿Cuánto gasta un hervidor o refrigerador?">Consumo clave</button>
        </div>

        <form id="ai-widget-form" class="ai-widget-input-form">
            <input type="text" id="ai-widget-input" class="ai-widget-input" placeholder="Escriba su consulta…" required autocomplete="off">
            <button type="submit" class="ai-widget-send-btn" title="Enviar">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>
    </div>

    <button type="button" class="ai-floating-btn" id="btn-toggle-ai-widget" title="Abrir asistente">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
        <span class="ai-btn-text">Asistente</span>
    </button>
</div>

<!-- Notificación -->
<div id="ecowatts-toast" class="ecowatts-toast">
    <div class="toast-icon" id="toast-icon">✓</div>
    <div class="toast-body">
        <div class="toast-title" id="toast-title">Artefacto añadido</div>
        <div class="toast-msg" id="toast-msg">Se agregó correctamente.</div>
    </div>
</div>

<!-- Modal de edición -->
<div id="editModal" class="modal-overlay" style="display: none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Modificar artefacto</h3>
            <button type="button" id="btn-close-modal" class="modal-close-btn">&times;</button>
        </div>
        <form id="form-edit-item">
            <input type="hidden" id="edit-item-id">
            <div class="form-group-clean">
                <label for="edit-item-name">Artefacto</label>
                <input type="text" id="edit-item-name" class="input-clean" readonly>
            </div>
            <div class="form-group-clean">
                <label for="edit-item-watts">Potencia (W)</label>
                <input type="number" id="edit-item-watts" class="input-clean" min="1" max="10000" required>
            </div>
            <div class="form-group-clean">
                <label for="edit-item-hours">Horas de uso al día</label>
                <input type="number" id="edit-item-hours" class="input-clean" min="0.1" max="24" step="0.1">
            </div>
            <div class="form-group-clean">
                <label for="edit-item-qty">Cantidad</label>
                <input type="number" id="edit-item-qty" class="input-clean" min="1" max="50" required>
            </div>
            <div class="modal-actions">
                <button type="button" id="btn-cancel-modal" class="btn btn-ghost">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://js.puter.com/v2/"></script>
<script src="js/dashboard.js?v=<?php echo time(); ?>"></script>
</body>
</html>