<?php
// Finds the private folder (code, config, data), starts the session, sets security headers.
//
// Local:     MailApp/public/   -> private folder is MailApp/
// DreamHost: ~/example.com/mailapp/  -> private folder is ~/mailapp-private/ (outside the website)

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'boot.php') { http_response_code(404); exit; }

$base = getenv('MAILAPP_PRIVATE') ?: null;
if (!$base) {
    foreach ([__DIR__ . '/..', dirname(__DIR__, 2) . '/mailapp-private'] as $dir) {
        if (is_file($dir . '/src/App.php')) { $base = $dir; break; }
    }
}
if (!$base) { http_response_code(500); exit('MailApp private folder not found (see README).'); }
define('MAILAPP_BASE', realpath($base));

require_once MAILAPP_BASE . '/src/App.php';
require_once MAILAPP_BASE . '/src/Auth.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

Auth::startSession(MAILAPP_BASE . '/data');
$auth = new Auth(MAILAPP_BASE . '/data');
