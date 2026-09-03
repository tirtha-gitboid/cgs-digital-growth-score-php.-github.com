<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/Db.php';

header('Content-Type: application/json');

echo json_encode([
    'status' => 'ok',
    'service' => 'CGS Digital Growth Score',
    'db_backend' => Db::backendName(),
    'pagespeed_configured' => trim(cgs_env('PAGESPEED_API_KEY', '')) !== '',
]);
