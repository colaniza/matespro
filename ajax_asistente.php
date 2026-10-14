<?php
/**
 * ECOWATTS - API Asistente Inteligente (Gemini / Base de Conocimiento Especializada en Eficiencia Energética)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario']) || !isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesión no activa.']);
    exit();
}

$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);

$pregunta = isset($_POST['pregunta']) ? trim($_POST['pregunta']) : (isset($inputData['pregunta']) ? trim($inputData['pregunta']) : '');
$contexto = isset($_POST['contexto']) ? $_POST['contexto'] : (isset($inputData['contexto']) ? $inputData['contexto'] : []);

if (empty($pregunta)) {
    echo json_encode(['success' => false, 'error' => 'Por favor escribe tu consulta o pregunta.']);
    exit();
}

// 1. Intentar llamar a Google Gemini API si existe variable de entorno o clave
$apiKey = getenv('GEMINI_API_KEY');
if (!$apiKey && defined('GEMINI_API_KEY')) {
    $apiKey = GEMINI_API_KEY;
}

$comuna = isset($contexto['comuna']) ? $contexto['comuna'] : 'Chile';
$tarifa = isset($contexto['tarifa']) ? $contexto['tarifa'] : '150';
$totalMes = isset($contexto['total_mes']) ? $contexto['total_mes'] : '0';
$cantArtefactos = isset($contexto['total_items']) ? $contexto['total_items'] : '0';

$systemPrompt = "Eres 'EcoBot', el Asistente Experto de IA de la plataforma Ecowatts (Chile).
Tu misión es:
1. Explicar el funcionamiento de Ecowatts: permite simular artefactos eléctricos, calcular el gasto en pesos chilenos (\$ CLP), por hora, día, mes o año, según la tarifa oficial de la comuna seleccionada en Chile.
2. Ayudar a los usuarios a reducir su factura eléctrica y optimizar el consumo de energía (consejos contra consumo vampiro, uso eficiente de refrigerador, hervidor, aire acondicionado, ampolletas LED, etc.).
3. Responder con tono amigable, claro, didáctico y motivador. Usa formato con viñetas cuando sea apropiado.
4. Datos del usuario actual: Comuna: {$comuna}, Tarifa eléctrica: \${$tarifa}/kWh, Total estimado en su carrito: \${$totalMes} CLP con {$cantArtefactos} artefactos.";

$respuestaTexto = null;

if (!empty($apiKey)) {
    try {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey);
        $payload = [
            "system_instruction" => [
                "parts" => [
                    ["text" => $systemPrompt]
                ]
            ],
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $pregunta]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.7,
                "maxOutputTokens" => 800
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $res) {
            $jsonRes = json_decode($res, true);
            if (isset($jsonRes['candidates'][0]['content']['parts'][0]['text'])) {
                $respuestaTexto = $jsonRes['candidates'][0]['content']['parts'][0]['text'];
            }
        }
    } catch (Exception $e) {
        $respuestaTexto = null;
    }
}

// 2. Si no hay API key o no hubo respuesta externa, usar Motor de Inteligencia y Conocimiento Experto Integrado
if (empty($respuestaTexto)) {
    $q = mb_strtolower($pregunta, 'UTF-8');

    if (preg_match('/(que es|qué es|como funciona|cómo funciona|de que trata|de qué trata|que hace|para que sirve|explicar|programa|plataforma|ecowatts)/i', $q)) {
        $respuestaTexto = "✨ **¿Qué es y cómo funciona Ecowatts?**\n\n"
            . "Ecowatts es una plataforma chilena diseñada para que conozcas **con precisión cuánto dinero gastas en luz por cada artefacto eléctrico** de tu hogar.\n\n"
            . "📌 **¿Cómo se utiliza?**\n"
            . "1. **Elige tu Comuna:** En la barra superior puedes seleccionar tu comuna en Chile (por ejemplo, actual: **{$comuna}** a \${$tarifa}/kWh). Así las tarifas se calculan exactamente según la distribuidora de tu zona (CGE, Enel, Chilquinta, Saesa, etc.).\n"
            . "2. **Calculadora de Artefactos:** Selecciona aparatos típicos (refrigerador, hervidor, TV, ampolletas) o crea los tuyos con sus Watts y horas de uso diario.\n"
            . "3. **Carrito & Total:** Visualiza el gasto desglosado por hora, por día, semana, mes o año en pesos chilenos (\$ CLP).\n"
            . "4. **Gráficos & Comparativas:** Analiza tu evolución mensual y descubre cuánto puedes ahorrar aplicando hábitos eficientes.";
    } elseif (preg_match('/(ahorro|ahorrar|menos luz|bajar la cuenta|reducir gasto|pagar menos|eficiencia)/i', $q)) {
        $respuestaTexto = "💡 **Consejos prácticos para pagar menos luz en {$comuna}:**\n\n"
            . "1. **Elimina el Consumo Vampiro (Standby):** Televisores, consolas, cargadores y microondas conectados consumen entre un 8% y 12% de tu cuenta mensual. Usa alargadores con interruptor y apágalos de noche.\n"
            . "2. **Refrigerador Eficiente:** Es el aparato que más consume al estar 24/7. Mantenlo a 4°C, no introduzcas comida caliente y revisa que las gomas de la puerta sellen bien.\n"
            . "3. **Hervidor de Agua:** Consume entre 1.500 y 2.000 Watts. Hierve solo el agua que vayas a tomar y guarda el sobrante en un termo.\n"
            . "4. **Iluminación LED:** Reemplaza ampolletas tradicionales por LED (9W a 12W), ahorran hasta un **85%** de energía.\n"
            . "5. **Lavadora a Carga Completa:** Lava siempre con agua fría si es posible, ya que calentar el agua consume el 90% de la energía del ciclo.\n\n"
            . "🎯 *Con estos hábitos puedes reducir tu cuenta mensual en aproximadamente un 25% a 30%.*";
    } elseif (preg_match('/(refrigerador|nevera|heladera)/i', $q)) {
        $respuestaTexto = "🧊 **Optimización del Refrigerador:**\n\n"
            . "El refrigerador representa cerca del **30% del consumo eléctrico residencial** promedio en Chile porque opera continuamente.\n\n"
            . "• **Potencia típica:** 150W a 250W (el motor compresor arranca intermitentemente unas 8 a 10 horas diarias efectivas).\n"
            . "• **Consejo clave:** Sepáralo al menos 10 cm de la pared para que ventile el condensador. Cada vez que abres la puerta por más de 30 segundos, el compresor debe trabajar el doble para recuperar la temperatura.";
    } elseif (preg_match('/(hervidor|tetera)/i', $q)) {
        $respuestaTexto = "☕ **Uso eficiente del Hervidor Eléctrico:**\n\n"
            . "El hervidor es uno de los aparatos de mayor potencia en tu cocina (típicamente **1.800W a 2.200W**).\n\n"
            . "• Aunque solo se use 15 a 30 minutos al día, su impacto en la tarifa es alto si se hierve agua a cada rato.\n"
            . "• **Recomendación:** Llénalo solo con las tazas necesarias o traspasa el agua hirviendo a un termo metálico; mantendrá el calor por 6 a 8 horas sin gastar 1 peso más de luz.";
    } elseif (preg_match('/(aire|calefactor|estufa|clima|frio|calor|calefaccion)/i', $q)) {
        $respuestaTexto = "❄️🔥 **Climatización y Calefacción:**\n\n"
            . "• **Aire acondicionado (Inverter):** Consume entre 900W y 1.400W. Fíjalo en 21°C a 23°C en invierno y 24°C en verano. Cada grado de ajuste extra puede incrementar el consumo un 7%.\n"
            . "• **Estufas halógenas o caloventores:** Tienen potencias muy altas (1.500W a 2.000W continuos). Mantener una estufa encendida 5 horas al día puede añadir más de \$30.000 a \$45.000 CLP a tu cuenta mensual.";
    } elseif (preg_match('/(tarifa|kwh|precio|comuna|valor)/i', $q)) {
        $respuestaTexto = "📍 **Tarifas Eléctricas en Chile y tu Comuna:**\n\n"
            . "En Chile el cobro de electricidad se realiza por **Kilowatt-hora (kWh)** consumido.\n\n"
            . "• Actualmente en **{$comuna}**, la tarifa aproximada registrada es de **\${$tarifa} CLP por kWh**.\n"
            . "• Puedes cambiar tu comuna en cualquier momento en el menú superior o en la vista de Gráficos para simular cómo varía el costo en distintas zonas del país.";
    } elseif (preg_match('/(grafico|gráfico|dashboard|analytics|estadistica|estadística|evolucion|evolución)/i', $q)) {
        $respuestaTexto = "📊 **Apartado de Gráficos y Analytics de Ecowatts:**\n\n"
            . "En la pestaña **'📊 Gráficos'** del menú lateral encontrarás:\n\n"
            . "1. **Consumo Mensual Comparativo:** Te muestra la tendencia y evolución proyectada de tu consumo mes a mes (kWh y \$ CLP).\n"
            . "2. **Gasto vs. Ahorro Estimado:** Enfrenta de forma visual tu gasto actual contra el dinero que ahorrarás implementando buenas prácticas de eficiencia energéticay consumo consciente.";
    } else {
        $respuestaTexto = "🤖 **¡Hola! Soy EcoBot, tu asistente de Ecowatts.**\n\n"
            . "Puedo ayudarte con todo lo relativo a tu consumo eléctrico en **{$comuna}**:\n\n"
            . "• **Explicación del programa:** Pregúntame cómo simular tus artefactos y calcular tus totales.\n"
            . "• **Consejos de Ahorro:** Te sugiero cómo bajar tu factura hasta un 30%.\n"
            . "• **Dudas sobre equipos:** Consumo de refrigeradores, hervidores, microondas, PCs, etc.\n"
            . "• **Gráficos e Indicadores:** Cómo interpretar la comparativa de Gasto vs. Ahorro.\n\n"
            . "¿Qué duda o idea te gustaría revisar?";
    }
}

echo json_encode([
    'success' => true,
    'respuesta' => $respuestaTexto
]);
