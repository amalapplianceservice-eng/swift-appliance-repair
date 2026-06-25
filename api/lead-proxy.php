<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$config = require __DIR__ . '/lead-config.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$name = trim((string)($data['name'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$address = trim((string)($data['address'] ?? ''));
$appliance = trim((string)($data['appliance_type'] ?? ''));
$pageUrl = trim((string)($data['page_url'] ?? ''));

if ($name === '' || $phone === '' || $address === '' || $appliance === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
    exit;
}

$sentAt = gmdate('Y-m-d H:i:s') . ' UTC';
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
if (function_exists('mb_substr')) {
    $userAgent = (string)mb_substr($userAgent, 0, 300);
} else {
    $userAgent = substr($userAgent, 0, 300);
}

$emailRecipients = array_values(array_filter((array)($config['email_recipients'] ?? []), static function ($v) {
    return is_string($v) && trim($v) !== '';
}));

$telegramToken = trim((string)($config['telegram_bot_token'] ?? ''));
$telegramChatId = trim((string)($config['telegram_chat_id'] ?? ''));

$telegramSent = false;
$telegramError = '';

if ($telegramToken === '') {
    $telegramError = 'Telegram token is empty.';
} elseif ($telegramChatId === '') {
    $telegramError = 'Telegram chat_id is empty.';
} elseif (!preg_match('/^-?\d+$/', $telegramChatId)) {
    $telegramError = 'Telegram chat_id must be numeric.';
} else {
    $message = "New Swift Appliance Repair Request\n\n"
        . "Name: {$name}\n"
        . "Phone: {$phone}\n"
        . "Address: {$address}\n"
        . "Appliance: {$appliance}\n"
        . "Time: {$sentAt}\n"
        . "IP: {$ip}\n"
        . "UA: {$userAgent}\n";

    if ($pageUrl !== '') {
        $message .= "Page: {$pageUrl}\n";
    }

    $endpoint = 'https://api.telegram.org/bot' . $telegramToken . '/sendMessage';
    $response = swift_post_json($endpoint, [
        'chat_id' => $telegramChatId,
        'text' => $message,
        'disable_web_page_preview' => true,
    ], 12);

    if (!$response['ok']) {
        $telegramError = 'Telegram transport failed.';
    } else {
        $body = json_decode($response['body'], true);
        if (is_array($body) && !empty($body['ok'])) {
            $telegramSent = true;
        } else {
            $desc = is_array($body) && isset($body['description']) ? (string)$body['description'] : '';
            $telegramError = $desc !== '' ? ('Telegram error: ' . $desc) : 'Telegram API rejected the request.';
        }
    }
}

$emailSent = false;
$emailError = '';

if (empty($emailRecipients)) {
    $emailError = 'No email recipients configured.';
} else {
    $subject = 'New Swift Appliance Repair request';
    $body = "New request from website\n\n"
        . "Name: {$name}\n"
        . "Phone: {$phone}\n"
        . "Address: {$address}\n"
        . "Appliance: {$appliance}\n"
        . "Time: {$sentAt}\n"
        . "IP: {$ip}\n"
        . "UA: {$userAgent}\n";

    if ($pageUrl !== '') {
        $body .= "Page: {$pageUrl}\n";
    }

    $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: Swift Appliance Repair <no-reply@' . $host . '>',
        'Reply-To: office.swiftappliance@gmail.com',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $emailSent = @mail(implode(',', $emailRecipients), $subject, $body, implode("\r\n", $headers));
    if (!$emailSent) {
        $emailError = 'mail() failed on server.';
    }
}

if ($telegramSent || $emailSent) {
    echo json_encode([
        'success' => true,
        'message' => 'Request sent successfully.',
        'channels' => [
            'telegram' => $telegramSent,
            'email' => $emailSent,
        ],
    ]);
    exit;
}

http_response_code(502);
$parts = [];
if ($telegramError !== '') {
    $parts[] = $telegramError;
}
if ($emailError !== '') {
    $parts[] = $emailError;
}
if (empty($parts)) {
    $parts[] = 'No delivery channel available.';
}

echo json_encode([
    'success' => false,
    'message' => 'Unable to deliver request. ' . implode(' ', $parts),
]);

function swift_post_json(string $url, array $payload, int $timeoutSeconds = 10): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'body' => ''];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $errNo = curl_errno($ch);
        curl_close($ch);

        return [
            'ok' => $errNo === 0 && is_string($resp),
            'body' => is_string($resp) ? $resp : '',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);

    $resp = @file_get_contents($url, false, $context);
    return [
        'ok' => is_string($resp),
        'body' => is_string($resp) ? $resp : '',
    ];
}
