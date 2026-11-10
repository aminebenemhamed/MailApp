<?php
// Microsoft sign-in (OAuth 2 + PKCE) for Hotmail / Outlook.com IMAP.
// Tokens are saved in ../data/ (never inside public/).

class OAuthRequired extends Exception {}

class MicrosoftOAuth
{
    private const AUTH = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize';
    private const TOKEN = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';
    // openid + email: tells us which Microsoft account was really signed in (IMAP needs that exact address).
    private const SCOPE = 'openid email offline_access https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send';
    private const VERSION = 2; // bump when SCOPE changes: older tokens must sign in again

    public function __construct(private array $account, private string $dataDir) {}

    private function file(): string
    {
        return $this->dataDir . '/oauth-' . preg_replace('/[^a-z0-9_-]/i', '', $this->account['id']) . '.json';
    }

    public static function redirectUri(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/oauth.php';
    }

    private function clientId(): string
    {
        $id = trim($this->account['client_id'] ?? '');
        if ($id === '') throw new RuntimeException("Microsoft client ID missing for {$this->account['name']} (see README)");
        return $id;
    }

    /** Step 1: send the browser to Microsoft. */
    public function start(): void
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $stateTok = bin2hex(random_bytes(16));
        $_SESSION['oauth'] = ['acc' => $this->account['id'], 'verifier' => $verifier, 'state' => $stateTok];
        $q = http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPE,
            'state' => $stateTok,
            'login_hint' => $this->account['user'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
        header('Location: ' . self::AUTH . '?' . $q);
    }

    /** Step 2: Microsoft sent us back with a code. */
    public function finish(string $code, string $verifier): void
    {
        $this->save($this->post([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
            'code_verifier' => $verifier,
        ]));
    }

    /** A valid access token, refreshed when needed. */
    public function accessToken(): string
    {
        $t = is_file($this->file()) ? json_decode((string)file_get_contents($this->file()), true) : null;
        if (!$t || empty($t['refresh_token'])) throw new OAuthRequired('Microsoft sign-in required');
        if (($t['v'] ?? 1) < self::VERSION) throw new OAuthRequired('Sign in to Microsoft once more to turn on sending');
        if (($t['expires_at'] ?? 0) > time() + 60) return $t['access_token'];
        try {
            $new = $this->post(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
        } catch (RuntimeException $e) {
            @unlink($this->file()); // refresh token revoked or expired: sign in again
            throw new OAuthRequired('Microsoft sign-in expired, please sign in again');
        }
        $new['refresh_token'] = $new['refresh_token'] ?? $t['refresh_token'];
        $new['email'] = $t['email'] ?? '';
        $this->save($new);
        return $new['access_token'];
    }

    /** Address of the Microsoft account that signed in ('' if unknown). */
    public function email(): string
    {
        $t = is_file($this->file()) ? json_decode((string)file_get_contents($this->file()), true) : null;
        return (string)($t['email'] ?? '');
    }

    public function forget(): void
    {
        @unlink($this->file());
    }

    private static function emailFromIdToken(string $jwt): string
    {
        $part = explode('.', $jwt)[1] ?? '';
        $claims = json_decode((string)base64_decode(strtr($part, '-_', '+/')), true) ?: [];
        foreach (['email', 'preferred_username'] as $k) {
            if (!empty($claims[$k]) && str_contains($claims[$k], '@')) return strtolower($claims[$k]);
        }
        return '';
    }

    private function post(array $fields): array
    {
        $fields['client_id'] = $this->clientId();
        $fields['scope'] = self::SCOPE;
        if (!empty($this->account['client_secret'])) $fields['client_secret'] = $this->account['client_secret'];
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => http_build_query($fields),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]);
        $body = @file_get_contents(self::TOKEN, false, $ctx);
        if ($body === false) throw new ImapError('Can’t reach Microsoft');
        $data = json_decode($body, true) ?: [];
        if (empty($data['access_token'])) {
            throw new RuntimeException('Microsoft: ' . ($data['error_description'] ?? $data['error'] ?? 'invalid response'));
        }
        return $data;
    }

    private function save(array $t): void
    {
        if (!is_dir($this->dataDir)) mkdir($this->dataDir, 0700, true);
        $out = [
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? '',
            'expires_at' => time() + (int)($t['expires_in'] ?? 3600),
            'email' => !empty($t['id_token']) ? self::emailFromIdToken($t['id_token']) : ($t['email'] ?? ''),
            'v' => self::VERSION,
        ];
        file_put_contents($this->file(), json_encode($out), LOCK_EX);
        @chmod($this->file(), 0600);
    }
}
