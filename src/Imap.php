<?php
// Minimal IMAP client in pure PHP (no imap extension needed, works on shared hosting).

class ImapError extends Exception {}

class Imap
{
    private $s;
    private int $tag = 0;

    public function __construct(string $host, int $port = 993, bool $ssl = true, int $timeout = 15)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $this->s = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) {
            throw new ImapError("Can’t connect to $host ($errstr)");
        }
        stream_set_timeout($this->s, 30);
        $greet = $this->readLine();
        if (!str_starts_with($greet, '* OK') && !str_starts_with($greet, '* PREAUTH')) {
            throw new ImapError('Unexpected server response: ' . trim($greet));
        }
    }

    public function __destruct()
    {
        if ($this->s) {
            @fwrite($this->s, 'Z LOGOUT' . "\r\n");
            @fclose($this->s);
        }
    }

    // ---------- low level ----------

    private function readLine(): string
    {
        $line = fgets($this->s);
        if ($line === false) {
            $meta = stream_get_meta_data($this->s);
            throw new ImapError($meta['timed_out'] ? 'Timed out (IMAP server)' : 'Connection closed by the server');
        }
        return $line;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, min(65536, $n - strlen($buf)));
            if ($chunk === false || ($chunk === '' && (feof($this->s) || stream_get_meta_data($this->s)['timed_out']))) {
                throw new ImapError('Connection lost while reading');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    // One full server response, with any {n} literals inlined.
    private function readResponse(): string
    {
        $resp = '';
        while (true) {
            $line = $this->readLine();
            $resp .= $line;
            if (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $m)) {
                $resp .= $this->readBytes((int)$m[1]);
                continue;
            }
            return $resp;
        }
    }

    /** Sends a command, returns the untagged responses. Throws on NO/BAD. */
    public function cmd(string $command): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, "$tag $command\r\n");
        $out = [];
        while (true) {
            $resp = $this->readResponse();
            if (str_starts_with($resp, "$tag ")) {
                $rest = trim(substr($resp, strlen($tag) + 1));
                if (!str_starts_with(strtoupper($rest), 'OK')) {
                    throw new ImapError(preg_replace('/^(NO|BAD)\s*/i', '', $rest));
                }
                return $out;
            }
            if ($resp[0] === '+') { // continuation (e.g. failed XOAUTH2): answer with an empty line
                fwrite($this->s, "\r\n");
                continue;
            }
            $out[] = $resp;
        }
    }

    public static function q(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    /** Parses an IMAP response into nested arrays. NIL becomes null. */
    public static function parse(string $s, int &$i = 0): array
    {
        $out = [];
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\r" || $c === "\n") { $i++; continue; }
            if ($c === ')') { $i++; return $out; }
            if ($c === '(') { $i++; $out[] = self::parse($s, $i); continue; }
            if ($c === '"') {
                $i++;
                $str = '';
                while ($i < $n && $s[$i] !== '"') {
                    if ($s[$i] === '\\') $i++;
                    $str .= $s[$i] ?? '';
                    $i++;
                }
                $i++;
                $out[] = $str;
                continue;
            }
            if ($c === '{' && preg_match('/\G\{(\d+)\+?\}\r?\n/', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                $out[] = substr($s, $i, (int)$m[1]);
                $i += (int)$m[1];
                continue;
            }
            $start = $i;
            $depth = 0;
            while ($i < $n) {
                $ch = $s[$i];
                if ($ch === '[') $depth++;
                elseif ($ch === ']') $depth--;
                elseif ($depth <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === "\r" || $ch === "\n")) break;
                $i++;
            }
            $atom = substr($s, $start, $i - $start);
            $out[] = strtoupper($atom) === 'NIL' ? null : $atom;
        }
        return $out;
    }

    private static function pairs(array $list): array
    {
        $kv = [];
        for ($k = 0; $k + 1 < count($list); $k += 2) {
            $kv[strtoupper((string)$list[$k])] = $list[$k + 1];
        }
        return $kv;
    }

    // ---------- commands ----------

    public function login(string $user, string $pass): void
    {
        $this->cmd('LOGIN ' . self::q($user) . ' ' . self::q($pass));
    }

    public function loginOAuth(string $user, string $accessToken): void
    {
        $this->cmd('AUTHENTICATE XOAUTH2 ' . base64_encode("user=$user\x01auth=Bearer $accessToken\x01\x01"));
    }

    /** @return array list of ['name' => raw name, 'label' => utf8 name, 'flags' => [..]] */
    public function folders(): array
    {
        $res = [];
        foreach ($this->cmd('LIST "" "*"') as $line) {
            $t = self::parse($line);
            if (strtoupper($t[1] ?? '') !== 'LIST' || !isset($t[4])) continue;
            $name = (string)$t[4];
            $label = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP') ?: $name;
            $res[] = ['name' => $name, 'label' => $label, 'flags' => array_map('strtolower', (array)$t[2])];
        }
        return $res;
    }

    public function status(string $folder): array
    {
        foreach ($this->cmd('STATUS ' . self::q($folder) . ' (MESSAGES UNSEEN UIDNEXT)') as $line) {
            $t = self::parse($line);
            if (strtoupper($t[1] ?? '') === 'STATUS') {
                $kv = self::pairs((array)end($t));
                return ['total' => (int)($kv['MESSAGES'] ?? 0), 'unseen' => (int)($kv['UNSEEN'] ?? 0), 'next' => (int)($kv['UIDNEXT'] ?? 0)];
            }
        }
        return ['total' => 0, 'unseen' => 0];
    }

    /** Selects a folder, returns the number of messages in it. */
    public function select(string $folder): int
    {
        $exists = 0;
        foreach ($this->cmd('SELECT ' . self::q($folder)) as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) $exists = (int)$m[1];
        }
        return $exists;
    }

    /** Newest messages first, $per per page. Returns ['total' => n, 'messages' => [...]]. */
    public function listMessages(string $folder, int $page = 1, int $per = 50): array
    {
        $total = $this->select($folder);
        $hi = $total - ($page - 1) * $per;
        if ($hi < 1) return ['total' => $total, 'messages' => []];
        $lo = max(1, $hi - $per + 1);

        $rows = [];
        $res = $this->cmd("FETCH $lo:$hi (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE)])");
        foreach ($res as $line) {
            $t = self::parse($line);
            if (strtoupper($t[2] ?? '') !== 'FETCH' || !is_array($t[3] ?? null)) continue;
            $kv = self::pairs($t[3]);
            $hdrText = '';
            foreach ($kv as $k => $v) if (str_starts_with($k, 'BODY[')) $hdrText = (string)$v;
            $h = Mime::parseHeaders($hdrText);
            $flags = array_map('strtolower', (array)($kv['FLAGS'] ?? []));
            $date = strtotime(Mime::first($h, 'date')) ?: strtotime((string)($kv['INTERNALDATE'] ?? '')) ?: 0;
            $rows[(int)$t[1]] = [
                'uid' => (int)($kv['UID'] ?? 0),
                'from' => Mime::address(Mime::first($h, 'from')),
                'subject' => Mime::decodeHeader(Mime::first($h, 'subject')),
                'date' => $date,
                'seen' => in_array('\\seen', $flags, true),
                'flagged' => in_array('\\flagged', $flags, true),
                'size' => (int)($kv['RFC822.SIZE'] ?? 0),
            ];
        }
        krsort($rows);
        return ['total' => $total, 'messages' => array_values($rows)];
    }

    public function setFlag(string $uid, string $flag, bool $on): void
    {
        $this->cmd("UID STORE $uid " . ($on ? '+' : '-') . "FLAGS.SILENT ($flag)");
    }

    /** Moves a message out of the selected folder. Uses MOVE when the server has it, COPY + delete otherwise. */
    public function move(string $uid, string $dest): void
    {
        try {
            $this->cmd("UID MOVE $uid " . self::q($dest));
        } catch (ImapError $e) {
            $this->cmd("UID COPY $uid " . self::q($dest));
            $this->expungeOne($uid);
        }
    }

    /** Permanently removes messages (UID set like "5,8,12") from the selected folder. */
    public function expungeOne(string $uid): void
    {
        $this->setFlag($uid, '\\Deleted', true);
        try {
            $this->cmd("UID EXPUNGE $uid");
        } catch (ImapError $e) {
            $this->cmd('EXPUNGE'); // server without UIDPLUS
        }
    }

    public function create(string $folder): void
    {
        try {
            $this->cmd('CREATE ' . self::q($folder));
        } catch (ImapError $e) {
            if (!preg_match('/exist/i', $e->getMessage())) throw $e;
        }
    }

    /** Folder prefix some servers require (e.g. "INBOX." on DreamHost/Courier). */
    public function personalPrefix(): string
    {
        try {
            foreach ($this->cmd('NAMESPACE') as $line) {
                $t = self::parse($line);
                if (strtoupper($t[1] ?? '') === 'NAMESPACE' && is_array($t[2] ?? null) && is_array($t[2][0] ?? null)) {
                    return (string)($t[2][0][0] ?? '');
                }
            }
        } catch (ImapError $e) {
        }
        return '';
    }

    /** Saves a message into a folder (e.g. Sent). */
    public function append(string $folder, string $msg, string $flags = '\\Seen'): void
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, "$tag APPEND " . self::q($folder) . " ($flags) {" . strlen($msg) . "}\r\n");
        while (true) {
            $resp = $this->readResponse();
            if ($resp[0] === '+') break;
            if (str_starts_with($resp, "$tag ")) throw new ImapError(trim(substr($resp, strlen($tag) + 1)));
        }
        fwrite($this->s, $msg . "\r\n");
        while (true) {
            $resp = $this->readResponse();
            if (str_starts_with($resp, "$tag ")) {
                $rest = trim(substr($resp, strlen($tag) + 1));
                if (!str_starts_with(strtoupper($rest), 'OK')) throw new ImapError(preg_replace('/^(NO|BAD)\s*/i', '', $rest));
                return;
            }
        }
    }

    /** Flags every message in the selected folder as read. */
    public function markAllRead(): void
    {
        $this->cmd('STORE 1:* +FLAGS.SILENT (\\Seen)');
    }

    /** Full raw message. Marks it as read. */
    public function fetchRaw(string $folder, int $uid): string
    {
        $this->select($folder);
        foreach ($this->cmd("UID FETCH $uid (BODY[])") as $line) {
            $t = self::parse($line);
            if (strtoupper($t[2] ?? '') !== 'FETCH' || !is_array($t[3] ?? null)) continue;
            $kv = self::pairs($t[3]);
            if (isset($kv['BODY[]'])) return (string)$kv['BODY[]'];
        }
        throw new ImapError('Message not found');
    }
}
