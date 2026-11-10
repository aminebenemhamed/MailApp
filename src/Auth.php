<?php
// One master password protects the whole app. Stored as a hash in data/auth.json.

class Auth
{
    private const IDLE = 14 * 86400;     // stay signed in 14 days without use
    private const MAX_FAILS = 5;         // then block that IP...
    private const LOCK = 15 * 60;        // ...for 15 minutes

    public function __construct(private string $dataDir) {}

    public static function startSession(string $dataDir): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        // Own session folder: shared hosting may clean the default one too early.
        $dir = $dataDir . '/sessions';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('mailapp');
        session_set_cookie_params([
            'lifetime' => self::IDLE, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string)self::IDLE);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    private function file(): string { return $this->dataDir . '/auth.json'; }

    public function hasPassword(): bool
    {
        return is_file($this->file());
    }

    public function isLoggedIn(): bool
    {
        if (empty($_SESSION['auth']) || ($_SESSION['seen'] ?? 0) < time() - self::IDLE) return false;
        // Changing the password signs out every other session.
        $data = json_decode((string)@file_get_contents($this->file()), true) ?: [];
        if (($_SESSION['ver'] ?? '') !== ($data['ver'] ?? '')) return false;
        if (time() - ($_SESSION['seen'] ?? 0) > 300) $_SESSION['seen'] = time();
        return true;
    }

    public static function isLocal(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }

    public function setPassword(string $pass): void
    {
        if (mb_strlen($pass) < 10) throw new InvalidArgumentException('The password must be at least 10 characters');
        if (!is_dir($this->dataDir)) mkdir($this->dataDir, 0700, true);
        $ver = bin2hex(random_bytes(8));
        file_put_contents($this->file(), json_encode(['hash' => password_hash($pass, PASSWORD_DEFAULT), 'ver' => $ver]), LOCK_EX);
        @chmod($this->file(), 0600);
        $this->signIn($ver);
    }

    public function login(string $pass): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $fails = $this->fails();
        $mine = $fails[$ip] ?? ['n' => 0, 't' => 0];
        if ($mine['n'] >= self::MAX_FAILS && $mine['t'] > time() - self::LOCK) {
            $min = (int)ceil(($mine['t'] + self::LOCK - time()) / 60);
            throw new RuntimeException("Too many attempts. Try again in $min min.");
        }
        $data = json_decode((string)@file_get_contents($this->file()), true) ?: [];
        if (empty($data['hash']) || !password_verify($pass, $data['hash'])) {
            usleep(800000);
            $fails[$ip] = ['n' => ($mine['t'] > time() - self::LOCK ? $mine['n'] : 0) + 1, 't' => time()];
            $this->saveFails($fails);
            throw new RuntimeException('Wrong password');
        }
        unset($fails[$ip]);
        $this->saveFails($fails);
        $this->signIn($data['ver'] ?? '');
    }

    private function signIn(string $ver): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['seen'] = time();
        $_SESSION['ver'] = $ver;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private function fails(): array
    {
        $f = $this->dataDir . '/attempts.json';
        $list = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        return array_filter($list, fn($x) => ($x['t'] ?? 0) > time() - self::LOCK);
    }

    private function saveFails(array $list): void
    {
        if (!is_dir($this->dataDir)) mkdir($this->dataDir, 0700, true);
        file_put_contents($this->dataDir . '/attempts.json', json_encode($list), LOCK_EX);
    }
}
