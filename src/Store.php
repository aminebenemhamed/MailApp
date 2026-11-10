<?php
// Accounts added from the app: saved in data/accounts.json, passwords encrypted with a key in data/.key.

class Store
{
    public function __construct(private string $dir) {}

    private function file(): string { return $this->dir . '/accounts.json'; }

    public function all(): array
    {
        if (!is_file($this->file())) return [];
        $list = json_decode((string)file_get_contents($this->file()), true);
        return is_array($list) ? $list : [];
    }

    public function saveAll(array $list): void
    {
        $this->ensureDir();
        file_put_contents($this->file(), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->file(), 0600);
    }

    /** Ids of config.php accounts the user removed from the app. */
    public function hidden(): array
    {
        $f = $this->dir . '/hidden.json';
        $list = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
        return is_array($list) ? $list : [];
    }

    public function hide(string $id): void
    {
        $this->ensureDir();
        $list = array_values(array_unique(array_merge($this->hidden(), [$id])));
        file_put_contents($this->dir . '/hidden.json', json_encode($list), LOCK_EX);
        @chmod($this->dir . '/hidden.json', 0600);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) mkdir($this->dir, 0700, true);
        // Extra safety if the folder ever ends up inside the web root.
        if (!is_file($this->dir . '/.htaccess')) file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }

    private function key(): string
    {
        $f = $this->dir . '/.key';
        if (!is_file($f)) {
            $this->ensureDir();
            file_put_contents($f, base64_encode(random_bytes(32)), LOCK_EX);
            @chmod($f, 0600);
        }
        return base64_decode(trim((string)file_get_contents($f)));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Can’t read the saved password');
        return $plain;
    }
}
