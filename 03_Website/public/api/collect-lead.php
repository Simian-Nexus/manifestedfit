<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$honeypot = trim((string)($_POST['company'] ?? ''));
if ($honeypot !== '') {
    echo json_encode(['ok' => true, 'redirect' => '/thank-you/']);
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$leadMagnet = trim((string)($_POST['lead_magnet'] ?? 'unknown'));

if ($name === '' || strlen($name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Name is required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Valid email is required']);
    exit;
}

$storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Storage is not available']);
    exit;
}

$csvPath = $storageDir . DIRECTORY_SEPARATOR . 'leads.csv';
$isNewFile = !file_exists($csvPath);
$handle = fopen($csvPath, 'ab');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lead file is not writable']);
    exit;
}

flock($handle, LOCK_EX);
if ($isNewFile) {
    fputcsv($handle, [
        'created_at',
        'name',
        'email',
        'lead_magnet',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'ip',
        'referrer',
        'user_agent'
    ]);
}

fputcsv($handle, [
    gmdate('c'),
    $name,
    strtolower($email),
    $leadMagnet,
    trim((string)($_POST['utm_source'] ?? '')),
    trim((string)($_POST['utm_medium'] ?? '')),
    trim((string)($_POST['utm_campaign'] ?? '')),
    trim((string)($_POST['utm_content'] ?? '')),
    trim((string)($_POST['utm_term'] ?? '')),
    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    (string)($_SERVER['HTTP_REFERER'] ?? ''),
    (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
]);

flock($handle, LOCK_UN);
fclose($handle);

echo json_encode([
    'ok' => true,
    'redirect' => '/thank-you/?lead=' . rawurlencode($leadMagnet)
]);
