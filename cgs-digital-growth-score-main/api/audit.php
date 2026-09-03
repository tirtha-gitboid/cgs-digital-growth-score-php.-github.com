<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/Audit.php';

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

$website = trim((string) ($body['website'] ?? ''));
$businessName = mb_substr(trim((string) ($body['business_name'] ?? '')), 0, 200);
$city = mb_substr(trim((string) ($body['city'] ?? '')), 0, 100);
$industry = mb_substr(trim((string) ($body['industry'] ?? '')), 0, 100);

if (mb_strlen($website) < 4 || mb_strlen($website) > 2048) {
    http_response_code(400);
    echo json_encode(['detail' => 'Website URL is required.']);
    return;
}

try {
    $url = Audit::normalizeUrl($website);
    $result = Audit::auditSite($url);
    $result['business_name'] = $businessName;
    $result['city'] = $city;
    $result['industry'] = $industry;
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (CgsValueError $e) {
    http_response_code(400);
    echo json_encode(['detail' => $e->getMessage()]);
} catch (CgsFetchError $e) {
    http_response_code(502);
    echo json_encode(['detail' => 'Could not fetch the website: ' . substr($e->getMessage(), 0, 180)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['detail' => 'Audit failed: ' . substr($e->getMessage(), 0, 180)]);
}
