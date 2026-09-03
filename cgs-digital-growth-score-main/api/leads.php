<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/Db.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['detail' => 'Method not allowed.']);
    return;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['detail' => 'Invalid JSON body.']);
    return;
}

$name = mb_substr(trim((string) ($body['name'] ?? '')), 0, 100);
$email = mb_substr(trim((string) ($body['email'] ?? '')), 0, 200);
$phone = mb_substr(trim((string) ($body['phone'] ?? '')), 0, 50);
$company = mb_substr(trim((string) ($body['company'] ?? '')), 0, 200);
$website = mb_substr(trim((string) ($body['website'] ?? '')), 0, 2048);

if (mb_strlen($name) < 2) {
    http_response_code(422);
    echo json_encode(['detail' => 'Name must be at least 2 characters.']);
    return;
}
if (mb_strlen($email) < 5) {
    http_response_code(422);
    echo json_encode(['detail' => 'A valid email is required.']);
    return;
}

try {
    Db::initDb();
    Db::saveLead($name, $email, $phone, $company, $website);
    echo json_encode(['success' => true, 'message' => 'Lead saved successfully.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['detail' => 'Could not save lead: ' . substr($e->getMessage(), 0, 180)]);
}
