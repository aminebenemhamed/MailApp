<?php
// Glue between the web API and the IMAP accounts listed in config.php.

require_once __DIR__ . '/Imap.php';
require_once __DIR__ . '/Mime.php';
require_once __DIR__ . '/OAuth.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Smtp.php';

class App
{
    private array $config;
    private Store $store;

    // Providers offered in "Ajouter un compte".
    public const PROVIDERS = [
        'gmail' => ['name' => 'Gmail', 'color' => '#ea4335', 'host' => 'imap.gmail.com'],
        'outlook' => ['name' => 'Outlook', 'color' => '#0078d4', 'host' => 'outlook.office365.com', 'auth' => 'oauth'],
        'yahoo' => ['name' => 'Yahoo', 'color' => '#6001d2', 'host' => 'imap.mail.yahoo.com'],
        'icloud' => ['name' => 'iCloud', 'color' => '#3693f3', 'host' => 'imap.mail.me.com'],
        'whatsapp' => ['name' => 'WhatsApp', 'color' => '#25d366', 'type' => 'link', 'url' => 'https://web.whatsapp.com/'],
        'imap' => ['name' => 'Mail', 'color' => '#8b8b92'],
    ];

    // Special folders: IMAP special-use flag first, then common names (servers/languages differ).
    private const ROLES = [
        'spam' => ['\\junk', ['spam', 'junk', 'bulk', 'bulk mail', 'junk e-mail', 'junk email', 'inbox.spam', 'inbox.junk',
            '[gmail]/spam', 'courrier indésirable', 'indésirables', 'pourriel']],
        'trash' => ['\\trash', ['trash', 'deleted messages', 'deleted items', 'inbox.trash', '[gmail]/trash', '[gmail]/corbeille',
            '[gmail]/bin', 'corbeille', 'éléments supprimés', 'deleted']],
        'archive' => ['\\archive', ['archive', 'archives', 'inbox.archive', 'inbox.archives']],
        'all' => ['\\all', []],
        'sent' => ['\\sent', ['sent', 'sent messages', 'sent items', 'sent mail', 'inbox.sent', 'inbox.sent messages',
            '[gmail]/sent mail', '[gmail]/messages envoyés', 'envoyés', 'messages envoyés', 'éléments envoyés']],
    ];

    public function __construct(string $configFile)
    {
        $this->config = is_file($configFile) ? (require $configFile) : ['accounts' => []];
        $this->store = new Store(dirname(__DIR__) . '/data');
    }

    /** Accounts from config.php, then the ones added from the app. */
    private function allAccounts(): array
    {
        $list = [];
        $hidden = $this->store->hidden();
        foreach ($this->config['accounts'] ?? [] as $a) {
            if (($a['enabled'] ?? true) && !in_array($a['id'], $hidden, true)) $list[] = $a + ['source' => 'config'];
        }
        foreach ($this->store->all() as $a) $list[] = $a + ['source' => 'app'];
        return $list;
    }

    public function accounts(): array
    {
        $out = [];
        foreach ($this->allAccounts() as $a) {
            $out[] = [
                'id' => $a['id'], 'name' => $a['name'], 'email' => $a['user'] ?? '', 'color' => $a['color'] ?? '#888',
                'type' => $a['type'] ?? 'mail', 'url' => $a['url'] ?? null, 'removable' => true,
            ];
        }
        return $out;
    }

    private function account(string $id): array
    {
        foreach ($this->allAccounts() as $a) {
            if ($a['id'] !== $id) continue;
            if (($a['type'] ?? 'mail') !== 'mail') throw new InvalidArgumentException('This account is not a mailbox');
            if (isset($a['pass_enc'])) $a['pass'] = $this->store->decrypt($a['pass_enc']);
            if (($a['auth'] ?? '') === 'oauth' && empty($a['client_id'])) $a['client_id'] = $this->defaultClientId();
            return $a;
        }
        throw new InvalidArgumentException('Unknown account');
    }

    /** Microsoft app ID shared by every Outlook/Hotmail account. */
    public function defaultClientId(): string
    {
        if (!empty($this->config['microsoft_client_id'])) return $this->config['microsoft_client_id'];
        foreach ($this->allAccounts() as $a) {
            if (!empty($a['client_id'])) return $a['client_id'];
        }
        return '';
    }

    /** Adds an account from the "Ajouter un compte" window. Tests the login first. */
    public function addAccount(array $in): array
    {
        $provider = (string)($in['provider'] ?? '');
        $p = self::PROVIDERS[$provider] ?? null;
        if (!$p) throw new InvalidArgumentException('Unknown provider');
        $id = $provider . '-' . bin2hex(random_bytes(3));
        $name = trim((string)($in['name'] ?? '')) ?: $p['name'];
        $name = mb_substr($name, 0, 40);

        if (($p['type'] ?? '') === 'link') {
            $acc = ['id' => $id, 'name' => $name, 'color' => $p['color'], 'type' => 'link', 'url' => $p['url'], 'user' => ''];
            $this->store->saveAll(array_merge($this->store->all(), [$acc]));
            return ['id' => $id];
        }

        $email = trim((string)($in['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid email address');
        foreach ($this->allAccounts() as $a) {
            if (strcasecmp($a['user'] ?? '', $email) === 0) throw new InvalidArgumentException('This account is already added');
        }
        if ($provider === 'imap' && $name === $p['name']) $name = substr(strrchr($email, '@'), 1);

        $host = $p['host'] ?? strtolower(trim((string)($in['host'] ?? '')));
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) throw new InvalidArgumentException('Invalid IMAP server');
        $port = $provider === 'imap' ? (int)($in['port'] ?? 993) : 993;
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Invalid port');

        $acc = ['id' => $id, 'name' => $name, 'color' => $p['color'], 'host' => $host, 'port' => $port, 'user' => $email];
        if (($p['auth'] ?? '') === 'oauth') {
            $acc['auth'] = 'oauth';
            $cid = trim((string)($in['client_id'] ?? '')) ?: $this->defaultClientId();
            if (!preg_match('/^[0-9a-f-]{36}$/i', $cid)) throw new InvalidArgumentException('Microsoft client ID missing or invalid (see README)');
            $acc['client_id'] = $cid;
            $this->store->saveAll(array_merge($this->store->all(), [$acc]));
            return ['id' => $id, 'oauth' => true];
        }

        $pass = (string)($in['pass'] ?? '');
        if ($pass === '') throw new InvalidArgumentException('Password missing');
        $imap = new Imap($host, $port, true); // throws with a clear message if the login is wrong
        $imap->login($email, $pass);
        unset($imap);
        $acc['pass_enc'] = $this->store->encrypt($pass);
        $this->store->saveAll(array_merge($this->store->all(), [$acc]));
        return ['id' => $id];
    }

    public function removeAccount(string $id): array
    {
        $list = $this->store->all();
        $keep = array_filter($list, fn($a) => $a['id'] !== $id);
        if (count($keep) !== count($list)) {
            $this->store->saveAll($keep);
        } else {
            // Account from config.php: hide it (config.php itself is not changed).
            $known = array_column($this->config['accounts'] ?? [], 'id');
            if (!in_array($id, $known, true)) throw new InvalidArgumentException('Unknown account');
            $this->store->hide($id);
        }
        @unlink(dirname(__DIR__) . '/data/oauth-' . preg_replace('/[^a-z0-9_-]/i', '', $id) . '.json');
        return ['ok' => true];
    }

    private function connect(array $a): Imap
    {
        if (($a['auth'] ?? 'password') === 'oauth') {
            $oauth = $this->oauth($a['id']);
            $token = $oauth->accessToken(); // before connecting: may need sign-in
            $imap = new Imap($a['host'], (int)($a['port'] ?? 993), $a['ssl'] ?? true);
            try {
                // The address of the account that really signed in (an alias in config.php would be refused).
                $imap->loginOAuth($oauth->email() ?: $a['user'], $token);
            } catch (ImapError $e) {
                if (stripos($e->getMessage(), 'not connected') === false) throw $e;
                $oauth->forget();
                throw new OAuthRequired('Microsoft refused IMAP access. Sign in again with the right account'
                    . ($a['user'] ? " ({$a['user']})" : '') . '. If it keeps happening: Outlook.com → Settings → Mail → Forwarding and IMAP → turn on IMAP.');
            }
            return $imap;
        }
        $imap = new Imap($a['host'], (int)($a['port'] ?? 993), $a['ssl'] ?? true);
        $imap->login($a['user'], $a['pass']);
        return $imap;
    }

    public function oauth(string $id): MicrosoftOAuth
    {
        return new MicrosoftOAuth($this->account($id), dirname(__DIR__) . '/data');
    }

    private function folderName(array $a, Imap $imap, string $box): ?string
    {
        if ($box === 'inbox') return 'INBOX';
        if ($box !== 'spam') throw new InvalidArgumentException('Unknown folder');
        return $this->roleFolder($a, $imap, 'spam');
    }

    /** Finds the account's spam / trash / archive folder (cached in the session). */
    private function roleFolder(array $a, Imap $imap, string $role): ?string
    {
        if (!empty($a[$role])) return $a[$role];
        $key = "folder_{$role}_{$a['id']}";
        if (!empty($_SESSION[$key])) return $_SESSION[$key];

        [$flag, $names] = self::ROLES[$role];
        $found = null;
        $folders = $imap->folders();
        foreach ($folders as $f) {
            if (in_array($flag, $f['flags'], true)) { $found = $f['name']; break; }
        }
        if (!$found) {
            foreach ($folders as $f) {
                if (in_array(mb_strtolower($f['label']), $names, true)) { $found = $f['name']; break; }
            }
        }
        if ($found) $this->remember($key, $found);
        return $found;
    }

    private function remember(string $key, string $value): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
            $_SESSION[$key] = $value;
            session_write_close();
        }
    }

    /** delete | archive | flag | unflag | notspam */
    /**
     * $uids: "12" or "12,15,20". $do: read | unread | flag | unflag | delete | archive | notspam | readall
     */
    public function action(string $id, string $box, string $uids, string $do): array
    {
        $a = $this->account($id);
        $imap = $this->connect($a);
        $folder = $this->folderName($a, $imap, $box);
        if (!$folder) throw new InvalidArgumentException('Folder not found');
        $total = $imap->select($folder);

        if ($do === 'readall') {
            if ($total > 0) $imap->markAllRead();
            return ['ok' => true];
        }
        $list = array_values(array_unique(array_filter(array_map('intval', explode(',', $uids)), fn($n) => $n > 0)));
        if (!$list) throw new InvalidArgumentException('No message selected');
        $uid = implode(',', array_slice($list, 0, 1000));

        switch ($do) {
            case 'read':
            case 'unread':
                $imap->setFlag($uid, '\\Seen', $do === 'read');
                return ['ok' => true];
            case 'flag':
            case 'unflag':
                $imap->setFlag($uid, '\\Flagged', $do === 'flag');
                return ['ok' => true];
            case 'notspam':
                $imap->move($uid, 'INBOX');
                return ['ok' => true];
            case 'delete':
                $trash = $this->roleFolder($a, $imap, 'trash');
                if ($trash && $trash !== $folder) $imap->move($uid, $trash);
                else $imap->expungeOne($uid);
                return ['ok' => true];
            case 'archive':
                // No Archive folder (Gmail): use "All Mail", which on Gmail just takes the mail out of the inbox.
                $dest = $this->roleFolder($a, $imap, 'archive') ?? $this->roleFolder($a, $imap, 'all');
                if (!$dest) {
                    $dest = $imap->personalPrefix() . 'Archive';
                    $imap->create($dest);
                    $this->remember("folder_archive_{$a['id']}", $dest);
                }
                if ($dest !== $folder) $imap->move($uid, $dest);
                return ['ok' => true];
        }
        throw new InvalidArgumentException('Unknown action');
    }

    public function counts(string $id): array
    {
        $a = $this->account($id);
        $imap = $this->connect($a);
        $out = ['inbox' => $imap->status('INBOX'), 'spam' => null];
        $spam = $this->folderName($a, $imap, 'spam');
        if ($spam) $out['spam'] = $imap->status($spam);
        return $out;
    }

    public function listMessages(string $id, string $box, int $page): array
    {
        $a = $this->account($id);
        $imap = $this->connect($a);
        $folder = $this->folderName($a, $imap, $box);
        if (!$folder) return ['total' => 0, 'messages' => [], 'missing' => true];
        return $imap->listMessages($folder, max(1, $page), 50);
    }

    private function parsed(string $id, string $box, int $uid): array
    {
        $a = $this->account($id);
        $imap = $this->connect($a);
        $folder = $this->folderName($a, $imap, $box);
        if (!$folder) throw new ImapError('Spam folder not found');
        return Mime::parse($imap->fetchRaw($folder, $uid));
    }

    public function message(string $id, string $box, int $uid): array
    {
        $m = $this->parsed($id, $box, $uid);
        $h = $m['headers'];
        $text = null;
        $html = null;
        $attachments = [];
        $inline = [];
        foreach ($m['parts'] as $p) {
            if (!$p['attachment'] && $p['type'] === 'text/html' && $html === null) $html = $p['data'];
            elseif (!$p['attachment'] && $p['type'] === 'text/plain' && $text === null) $text = $p['data'];
            elseif ($p['attachment']) {
                if ($p['cid'] !== '' && str_starts_with($p['type'], 'image/')) $inline[$p['cid']] = $p;
                if ($p['filename'] !== '' || $p['cid'] === '') {
                    $attachments[] = ['part' => $p['path'], 'name' => $p['filename'] ?: 'attachment', 'type' => $p['type'], 'size' => strlen($p['data'])];
                }
            }
        }
        // Inline images (cid:) become data: URLs so they show without extra requests.
        if ($html !== null && $inline) {
            $html = preg_replace_callback('/cid:([^"\'\s)>]+)/i', function ($mm) use ($inline) {
                $p = $inline[$mm[1]] ?? $inline[rawurldecode($mm[1])] ?? null;
                if (!$p || strlen($p['data']) > 3_000_000) return $mm[0];
                return 'data:' . $p['type'] . ';base64,' . base64_encode($p['data']);
            }, $html);
        }
        return [
            'subject' => Mime::decodeHeader(Mime::first($h, 'subject')),
            'from' => Mime::address(Mime::first($h, 'from')),
            'to' => Mime::addressList(Mime::first($h, 'to')),
            'cc' => Mime::addressList(Mime::first($h, 'cc')),
            'to_list' => Mime::addresses(Mime::first($h, 'to')),
            'cc_list' => Mime::addresses(Mime::first($h, 'cc')),
            'reply_to' => Mime::addresses(Mime::first($h, 'reply-to')),
            'me' => strtolower($this->fromAddress($this->account($id))),
            'date' => strtotime(Mime::first($h, 'date')) ?: 0,
            'html' => $html,
            'text' => $text,
            'attachments' => $attachments,
        ];
    }

    // ---------- sending ----------

    // SMTP server for each IMAP host. Other servers: "imap." becomes "smtp." on port 465.
    // An account can also set 'smtp_host' / 'smtp_port' in config.php.
    private const SMTP = [
        'imap.gmail.com' => ['smtp.gmail.com', 465],
        'imap.mail.me.com' => ['smtp.mail.me.com', 587],
        'imap.mail.yahoo.com' => ['smtp.mail.yahoo.com', 465],
        'outlook.office365.com' => ['smtp-mail.outlook.com', 587],
        'imap-mail.outlook.com' => ['smtp-mail.outlook.com', 587],
        'imap.dreamhost.com' => ['smtp.dreamhost.com', 465],
    ];
    // These servers already put a copy in "Sent" by themselves.
    private const AUTO_SENT = ['smtp.gmail.com', 'smtp-mail.outlook.com', 'smtp.office365.com'];
    private const MAX_SEND = 20 * 1024 * 1024;

    private function fromAddress(array $a): string
    {
        if (!empty($a['from'])) return $a['from'];
        if (($a['auth'] ?? '') === 'oauth') return $this->oauth($a['id'])->email() ?: $a['user'];
        return $a['user'];
    }

    private function smtpServer(array $a): array
    {
        if (!empty($a['smtp_host'])) return [$a['smtp_host'], (int)($a['smtp_port'] ?? 465)];
        $host = strtolower($a['host']);
        return self::SMTP[$host] ?? [preg_replace('/^imap\./', 'smtp.', $host), 465];
    }

    /**
     * $in: from (account id), to, cc, bcc, subject, body,
     *      and for a reply / forward: ref_acc, ref_box, ref_uid, mode (reply | forward).
     * $files: uploaded files as [['name' =>, 'type' =>, 'tmp' =>], ...]
     */
    public function send(array $in, array $files): array
    {
        $a = $this->account((string)($in['from'] ?? ''));
        $from = $this->fromAddress($a);
        $to = Mime::addresses((string)($in['to'] ?? ''));
        $cc = Mime::addresses((string)($in['cc'] ?? ''));
        $bcc = Mime::addresses((string)($in['bcc'] ?? ''));
        $all = array_merge($to, $cc, $bcc);
        if (!$all) throw new InvalidArgumentException('Add at least one recipient');
        if (count($all) > 100) throw new InvalidArgumentException('Too many recipients (100 max)');
        foreach ($all as $r) {
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Invalid address: {$r['email']}");
        }
        $subject = trim((string)($in['subject'] ?? ''));
        $body = str_replace(["\r\n", "\r"], "\n", (string)($in['body'] ?? ''));

        // Original message for a reply or a forward.
        $refAcc = (string)($in['ref_acc'] ?? '');
        $refBox = (string)($in['ref_box'] ?? 'inbox');
        $refUid = (int)($in['ref_uid'] ?? 0);
        $mode = (string)($in['mode'] ?? '');
        $orig = null;
        if ($refAcc !== '' && $refUid > 0 && in_array($mode, ['reply', 'forward'], true)) $orig = $this->parsed($refAcc, $refBox, $refUid);

        $attach = [];
        foreach ($files as $f) {
            $data = (string)file_get_contents($f['tmp']);
            $attach[] = ['name' => $f['name'], 'type' => $f['type'] ?: 'application/octet-stream', 'data' => $data];
        }
        if ($orig && $mode === 'forward') {
            foreach ($orig['parts'] as $p) {
                if ($p['attachment'] && ($p['filename'] !== '' || $p['cid'] === '')) {
                    $attach[] = ['name' => $p['filename'] ?: 'attachment', 'type' => $p['type'], 'data' => $p['data']];
                }
            }
        }
        $size = strlen($body) + array_sum(array_map(fn($x) => strlen($x['data']), $attach));
        if ($size > self::MAX_SEND) throw new InvalidArgumentException('Message too large (20 MB max)');

        // Headers
        $domain = substr(strrchr($from, '@'), 1) ?: 'mailapp.local';
        $msgId = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $h = [
            'From: ' . Mime::formatAddress(['name' => $a['from_name'] ?? $this->config['from_name'] ?? '', 'email' => $from]),
            'Date: ' . date('r'),
            'Message-ID: ' . $msgId,
            'Subject: ' . Mime::encodeHeader($subject),
            'MIME-Version: 1.0',
        ];
        if ($to) $h[] = 'To: ' . implode(",\r\n ", array_map([Mime::class, 'formatAddress'], $to));
        if ($cc) $h[] = 'Cc: ' . implode(",\r\n ", array_map([Mime::class, 'formatAddress'], $cc));
        if ($orig && $mode === 'reply') {
            $oh = $orig['headers'];
            $origId = trim(Mime::first($oh, 'message-id'));
            if ($origId !== '') {
                $refs = trim(Mime::first($oh, 'references') . ' ' . $origId);
                $h[] = 'In-Reply-To: ' . $origId;
                $h[] = 'References: ' . wordwrap($refs, 900, "\r\n ", false);
            }
        }

        $text = "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace("\n", "\r\n", $body));
        if ($attach) {
            $b = 'mailapp-' . bin2hex(random_bytes(10));
            $h[] = "Content-Type: multipart/mixed; boundary=\"$b\"";
            $mime = "--$b\r\n$text\r\n";
            foreach ($attach as $x) {
                $name = str_replace(['"', "\r", "\n"], '', $x['name']);
                $type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $x['type']) ? $x['type'] : 'application/octet-stream';
                $enc = Mime::encodeHeader($name);
                $mime .= "--$b\r\nContent-Type: $type; name=\"$enc\"\r\nContent-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; filename=\"$enc\"; filename*=UTF-8''" . rawurlencode($name) . "\r\n\r\n"
                    . chunk_split(base64_encode($x['data']), 76, "\r\n");
            }
            $mime .= "--$b--\r\n";
            $raw = implode("\r\n", $h) . "\r\n\r\n" . $mime;
        } else {
            $raw = implode("\r\n", $h) . "\r\n" . $text . "\r\n";
        }

        // Send
        [$host, $port] = $this->smtpServer($a);
        $smtp = new Smtp($host, $port);
        if (($a['auth'] ?? '') === 'oauth') {
            $oauth = $this->oauth($a['id']);
            $smtp->authXOAuth2($oauth->email() ?: $a['user'], $oauth->accessToken());
        } else {
            $smtp->authPlain($a['user'], $a['pass']);
        }
        $smtp->send($from, array_column($all, 'email'), $raw);
        unset($smtp);

        // Copy in "Sent", and mark the original as answered. Failures here don't undo the send.
        $warning = null;
        try {
            $needCopy = !in_array($host, self::AUTO_SENT, true);
            $imap = ($needCopy || ($orig && $mode === 'reply' && $refAcc === $a['id'])) ? $this->connect($a) : null;
            if ($needCopy) {
                $sent = $this->roleFolder($a, $imap, 'sent');
                if (!$sent) {
                    $sent = $imap->personalPrefix() . 'Sent';
                    $imap->create($sent);
                    $this->remember("folder_sent_{$a['id']}", $sent);
                }
                $imap->append($sent, $raw);
            }
            if ($orig && $mode === 'reply') {
                $ri = ($refAcc === $a['id'] && $imap) ? $imap : $this->connect($this->account($refAcc));
                $folder = $this->folderName($this->account($refAcc), $ri, $refBox);
                if ($folder) { $ri->select($folder); $ri->setFlag((string)$refUid, '\\Answered', true); }
            }
        } catch (Throwable $e) {
            $warning = 'Sent, but no copy saved in Sent (' . $e->getMessage() . ')';
        }
        return ['ok' => true, 'warning' => $warning];
    }

    /** Sends one attachment to the browser. */
    public function download(string $id, string $box, int $uid, string $part): void
    {
        $m = $this->parsed($id, $box, $uid);
        foreach ($m['parts'] as $p) {
            if ($p['path'] !== $part) continue;
            $name = $p['filename'] ?: 'attachment';
            header('Content-Type: ' . (preg_match('#^[\w.+-]+/[\w.+-]+$#', $p['type']) ? $p['type'] : 'application/octet-stream'));
            header('Content-Length: ' . strlen($p['data']));
            header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\w.\- ]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            echo $p['data'];
            return;
        }
        http_response_code(404);
        echo 'Attachment not found';
    }
}
