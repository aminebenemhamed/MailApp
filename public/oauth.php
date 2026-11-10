<?php
// Microsoft sign-in: ?acc=hotmail starts it, Microsoft comes back here with ?code=...

require __DIR__ . '/boot.php';
if (!$auth->isLoggedIn()) { header('Location: ./'); exit; }

function page(string $title, string $msg): void
{
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title);
    $m = htmlspecialchars($msg);
    echo "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><title>$t</title>
<body style='font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;background:#f7f7f5;color:#2b2d31;display:grid;place-items:center;min-height:90vh;margin:0;padding:16px'>
<div style='max-width:440px;text-align:center'><h2 style='font-weight:650'>$t</h2><p>$m</p><p><a href='./' style='color:#4a63d8'>Back to MailApp</a></p></div>";
}

try {
    $app = new App(MAILAPP_BASE . '/config.php');

    if (isset($_GET['acc'])) {
        $app->oauth((string)$_GET['acc'])->start();
        exit;
    }

    if (isset($_GET['error'])) {
        page('Sign-in cancelled', (string)($_GET['error_description'] ?? $_GET['error']));
        exit;
    }

    $saved = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);
    if (!$saved || !isset($_GET['code']) || !hash_equals($saved['state'], (string)($_GET['state'] ?? ''))) {
        page('Link expired', 'Start the sign-in again from MailApp.');
        exit;
    }
    $app->oauth($saved['acc'])->finish((string)$_GET['code'], $saved['verifier']);
    header('Location: ./');
} catch (Throwable $e) {
    page('Sign-in error', $e->getMessage());
}
