<?php
// Entry point: login page, the app page, or JSON when ?api=... is present.

require __DIR__ . '/boot.php';

$api = $_GET['api'] ?? null;
$json = function ($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

// POSTs must come from the app itself (blocks forged requests from other sites).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_MAILAPP'] ?? '') !== '1') $json(['error' => 'Request refused'], 403);

// ----- sign-in -----
if ($api === 'login' || $api === 'setup' || $api === 'logout') {
    try {
        if ($api === 'logout') $auth->logout();
        elseif ($api === 'login') $auth->login((string)($_POST['password'] ?? ''));
        else {
            // First password can only be created from the computer itself (never from the internet).
            if ($auth->hasPassword()) throw new RuntimeException('The password already exists');
            if (!Auth::isLocal()) throw new RuntimeException('Create the password locally first (see README)');
            $auth->setPassword((string)($_POST['password'] ?? ''));
        }
        $json(['ok' => true]);
    } catch (Throwable $e) {
        $json(['error' => $e->getMessage()], 400);
    }
}

if (!$auth->isLoggedIn()) {
    if ($api !== null) $json(['error' => 'Session expired, please sign in again', 'code' => 'auth'], 401);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $mode = $auth->hasPassword() ? 'login' : (Auth::isLocal() ? 'setup' : 'blocked');
    echo str_replace(['{{MODE}}', '"style.css"'], [$mode, '"style.css?v=' . @filemtime(__DIR__ . '/style.css') . '"'], file_get_contents(__DIR__ . '/login.html'));
    exit;
}

if ($api === null) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    // ?v=<file date> so the browser never keeps an old app.js / style.css after an update.
    $v = fn($f) => $f . '?v=' . @filemtime(__DIR__ . '/' . $f);
    echo str_replace(['"app.js"', '"style.css"'], ['"' . $v('app.js') . '"', '"' . $v('style.css') . '"'], file_get_contents(__DIR__ . '/app.html'));
    exit;
}

// Let several accounts load in parallel (PHP locks the session otherwise).
session_write_close();

try {
    $app = new App(MAILAPP_BASE . '/config.php');
    $acc = (string)($_GET['acc'] ?? '');
    $box = (string)($_GET['box'] ?? 'inbox');
    $uid = (int)($_GET['uid'] ?? 0);

    if ($api === 'att') {
        $app->download($acc, $box, $uid, (string)($_GET['part'] ?? ''));
        exit;
    }

    switch ($api) {
        case 'accounts': $data = $app->accounts(); break;
        case 'info':     $data = ['ms_client_id' => $app->defaultClientId() !== '']; break;
        case 'counts':   $data = $app->counts($acc); break;
        case 'list':     $data = $app->listMessages($acc, $box, (int)($_GET['page'] ?? 1)); break;
        case 'msg':      $data = $app->message($acc, $box, $uid); break;
        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST required');
            $data = $app->addAccount($_POST);
            break;
        case 'remove':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST required');
            $data = $app->removeAccount($acc);
            break;
        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST required');
            if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new InvalidArgumentException('Attachments too large for the server');
            $files = [];
            $up = $_FILES['files'] ?? null;
            if ($up && is_array($up['name'])) {
                foreach ($up['name'] as $k => $name) {
                    if ($up['error'][$k] === UPLOAD_ERR_NO_FILE) continue;
                    if ($up['error'][$k] !== UPLOAD_ERR_OK) throw new InvalidArgumentException("Attachment refused: $name");
                    $files[] = ['name' => $name, 'type' => $up['type'][$k], 'tmp' => $up['tmp_name'][$k]];
                }
            }
            $data = $app->send($_POST, $files);
            break;
        case 'action':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST required');
            $data = $app->action($acc, $box, (string)($_POST['uids'] ?? $uid), (string)($_POST['do'] ?? ''));
            break;
        default: throw new InvalidArgumentException('Unknown action');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage(), 'code' => $e instanceof OAuthRequired ? 'oauth_required' : null], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
