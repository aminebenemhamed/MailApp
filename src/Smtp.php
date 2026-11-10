<?php
// Minimal SMTP client in pure PHP: SSL (465) or STARTTLS (587), AUTH PLAIN or XOAUTH2.

class SmtpError extends Exception {}

class Smtp
{
    private $s;

    public function __construct(private string $host, int $port = 465, int $timeout = 20)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $implicit = !in_array($port, [25, 587, 2525], true); // 465 = SSL from the start, 587 = STARTTLS
        $this->s = @stream_socket_client(($implicit ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) throw new SmtpError("Can’t connect to the sending server $host ($errstr)");
        stream_set_timeout($this->s, 60);
        $this->expect(null, [220]);
        $this->ehlo();
        if (!$implicit) {
            $this->expect('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new SmtpError("TLS encryption failed with $host");
            }
            $this->ehlo();
        }
    }

    public function __destruct()
    {
        if ($this->s) {
            @fwrite($this->s, "QUIT\r\n");
            @fclose($this->s);
        }
    }

    private function ehlo(): void
    {
        $this->expect('EHLO ' . (gethostname() ?: 'localhost'), [250]);
    }

    /** Reads one (possibly multi-line) reply: [code, text]. */
    private function read(): array
    {
        $text = '';
        while (true) {
            $line = fgets($this->s);
            if ($line === false) throw new SmtpError('The sending server closed the connection');
            $text .= substr($line, 4);
            if (strlen($line) < 4 || $line[3] !== '-') return [(int)substr($line, 0, 3), trim($text)];
        }
    }

    private function expect(?string $command, array $codes): array
    {
        if ($command !== null) fwrite($this->s, $command . "\r\n");
        [$code, $text] = $this->read();
        if (!in_array($code, $codes, true)) {
            if ($code === 334) { // XOAUTH2 error details: send an empty line to get the final error
                fwrite($this->s, "\r\n");
                [$code, $text2] = $this->read();
                $text = $text2 ?: $text;
            }
            throw new SmtpError("Sending server: $code " . preg_replace('/\s+/', ' ', $text));
        }
        return [$code, $text];
    }

    public function authPlain(string $user, string $pass): void
    {
        $this->expect('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
    }

    public function authXOAuth2(string $user, string $token): void
    {
        $this->expect('AUTH XOAUTH2 ' . base64_encode("user=$user\x01auth=Bearer $token\x01\x01"), [235]);
    }

    /** $data: the full message with CRLF line endings. */
    public function send(string $from, array $rcpts, string $data): void
    {
        $this->expect("MAIL FROM:<$from>", [250]);
        foreach ($rcpts as $r) $this->expect("RCPT TO:<$r>", [250, 251]);
        $this->expect('DATA', [354]);
        $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
        fwrite($this->s, $data . (str_ends_with($data, "\r\n") ? '' : "\r\n") . ".\r\n");
        $this->expect(null, [250]);
    }
}
