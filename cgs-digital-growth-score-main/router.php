<?php
/**
 * Router for PHP's built-in development server.
 * Usage: php -S 127.0.0.1:8000 router.php
 *
 * Mirrors the FastAPI route table:
 *   GET  /             -> frontend/index.html
 *   GET  /static/*      -> frontend/*
 *   POST /api/audit     -> api/audit.php
 *   POST /api/leads     -> api/leads.php
 *   GET  /api/health    -> api/health.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

if ($uri === '/api/audit') {
    require __DIR__ . '/api/audit.php';
    return true;
}

if ($uri === '/api/leads') {
    require __DIR__ . '/api/leads.php';
    return true;
}

if ($uri === '/api/health') {
    require __DIR__ . '/api/health.php';
    return true;
}

if (preg_match('#^/static/(.+)$#', $uri, $m)) {
    $file = __DIR__ . '/frontend/' . $m[1];
    if (is_file($file)) {
        $mime = function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}

// Anything else: let the built-in server try to serve it as a static file
// from the document root, or 404 if it doesn't exist.
return false;
