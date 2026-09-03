<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/Db.php';

// Mirrors the FastAPI @app.on_event("startup") handler: make sure the
// leads table exists, and log a couple of helpful warnings.
Db::initDb();
if (Db::backendName() === 'sqlite') {
    error_log(
        'No DATABASE_URL set — leads are stored in local SQLite and will be ' .
        'LOST on restart/redeploy on most free hosting tiers. Set DATABASE_URL ' .
        'to a Postgres connection string for persistent lead storage.'
    );
}
if (trim(cgs_env('PAGESPEED_API_KEY', '')) === '') {
    error_log(
        'No PAGESPEED_API_KEY set — audits will run without Google PageSpeed ' .
        'Insights data (everything else still works).'
    );
}

header('Content-Type: text/html; charset=utf-8');
readfile(FRONTEND_DIR . '/index.html');
