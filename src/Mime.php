<?php
// Small MIME parser: headers, multipart, encodings, charsets, attachments.

class Mime
{
    public static function split(string $raw): array
    {
        if (preg_match('/^\r?\n/', $raw)) return ['', preg_replace('/^\r?\n/', '', $raw)];
        if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $p = $m[0][1];
            return [substr($raw, 0, $p), substr($raw, $p + strlen($m[0][0]))];
        }
        return [$raw, ''];
    }

    /** lowercase header name => list of raw values */
    public static function parseHeaders(string $h): array
    {
        $out = [];
        $h = preg_replace('/\r?\n[ \t]+/', ' ', $h);
        foreach (preg_split('/\r?\n/', $h) as $line) {
            $p = strpos($line, ':');
            if ($p === false) continue;
            $out[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
        }
        return $out;
    }

    public static function first(array $h, string $name): string
    {
        return $h[$name][0] ?? '';
    }

    public static function decodeHeader(string $v): string
    {
        if ($v === '') return '';
        if (!str_contains($v, '=?')) return self::toUtf8($v, '');
        // Remove whitespace between adjacent encoded words (RFC 2047)
        $v = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $v);
        $out = preg_replace_callback('/=\?([^?]+)\?([QqBb])\?([^?]*)\?=/', function ($m) {
            $cs = preg_replace('/\*.*$/', '', $m[1]); // drop RFC 2231 language
            if (strtoupper($m[2]) === 'B') {
                $txt = base64_decode($m[3]);
            } else {
                $txt = quoted_printable_decode(str_replace('_', ' ', $m[3]));
            }
            return self::toUtf8((string)$txt, $cs);
        }, $v);
        return self::toUtf8($out, '');
    }

    public static function toUtf8(string $s, ?string $charset): string
    {
        $cs = strtolower(trim((string)$charset, " \"'"));
        $aliases = [
            'utf8' => 'UTF-8', 'iso-8859-1' => 'Windows-1252', 'latin1' => 'Windows-1252', 'us-ascii' => 'Windows-1252',
            'ascii' => 'Windows-1252', 'ks_c_5601-1987' => 'CP949', 'gb2312' => 'GB18030', 'gbk' => 'GB18030',
            'x-gbk' => 'GB18030', 'windows-874' => 'CP874', 'iso-8859-8-i' => 'ISO-8859-8',
        ];
        if ($cs === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii' || $cs === 'ascii') {
            if (mb_check_encoding($s, 'UTF-8')) return $s;
            $cs = 'windows-1252';
        }
        $cs = $aliases[$cs] ?? $cs;
        $res = false;
        try {
            if (in_array(strtolower($cs), array_map('strtolower', mb_list_encodings()), true)) {
                $res = mb_convert_encoding($s, 'UTF-8', $cs);
            }
        } catch (Throwable $e) {
            $res = false;
        }
        if ($res === false) $res = @iconv($cs, 'UTF-8//IGNORE', $s);
        if ($res === false) $res = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        return $res;
    }

    /** "text/plain; charset=utf-8; name*=UTF-8''x.pdf" => ['value' => 'text/plain', 'charset' => ..., 'name' => ...] */
    public static function params(string $v): array
    {
        $parts = ['value' => strtolower(trim(strtok($v, ';') ?: ''))];
        preg_match_all('/;\s*([^=\s;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $v, $m, PREG_SET_ORDER);
        $ext = [];
        foreach ($m as $p) {
            $key = strtolower($p[1]);
            $val = trim($p[2]);
            if (strlen($val) > 1 && $val[0] === '"') $val = stripcslashes(substr($val, 1, -1));
            if (preg_match('/^([^*]+)\*(\d*)\*?$/', $key, $km)) {
                // RFC 2231: name*, name*0, name*0*, name*1* ...
                $ext[$km[1]][(int)$km[2]] = [$val, str_ends_with($key, '*')];
            } else {
                $parts[$key] = $val;
            }
        }
        foreach ($ext as $name => $segs) {
            ksort($segs);
            $charset = '';
            $str = '';
            foreach ($segs as $idx => [$val, $encoded]) {
                if ($encoded) {
                    if ($idx === array_key_first($segs) && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $cm)) {
                        $charset = $cm[1];
                        $val = $cm[2];
                    }
                    $val = rawurldecode($val);
                }
                $str .= $val;
            }
            $parts[$name] = self::toUtf8($str, $charset);
        }
        foreach (['name', 'filename'] as $k) {
            if (isset($parts[$k])) $parts[$k] = self::decodeHeader($parts[$k]);
        }
        return $parts;
    }

    /** "Name" <a@b.c>  =>  ['name' => 'Name', 'email' => 'a@b.c'] */
    public static function address(string $raw): array
    {
        $v = self::decodeHeader($raw);
        if (preg_match('/^\s*"?(.*?)"?\s*<([^>]+)>/', $v, $m)) {
            return ['name' => trim(stripslashes($m[1])), 'email' => trim($m[2])];
        }
        return ['name' => '', 'email' => trim($v)];
    }

    public static function addressList(string $raw): string
    {
        return trim(self::decodeHeader($raw));
    }

    /** "A <a@x>, \"B, C\" <b@x>, c@x"  =>  list of ['name' => .., 'email' => ..] */
    public static function addresses(string $raw): array
    {
        $out = [];
        foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw) as $one) {
            if (trim($one) === '') continue;
            $a = self::address($one);
            if (str_contains($a['email'], '@')) $out[] = $a;
        }
        return $out;
    }

    // ---------- writing ----------

    /** Encodes a header value for sending (RFC 2047) when it is not plain ASCII. */
    public static function encodeHeader(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        if (!preg_match('/[^\x20-\x7e]/', $s)) return $s;
        $words = [];
        // Chunks of up to 45 bytes, cut on whole UTF-8 characters.
        $buf = '';
        foreach (mb_str_split($s) as $ch) {
            if (strlen($buf . $ch) > 45) { $words[] = $buf; $buf = ''; }
            $buf .= $ch;
        }
        if ($buf !== '') $words[] = $buf;
        return implode("\r\n ", array_map(fn($w) => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
    }

    public static function formatAddress(array $a): string
    {
        $email = $a['email'];
        $name = trim($a['name'] ?? '');
        if ($name === '') return $email;
        if (preg_match('/[^\x20-\x7e]/', $name)) return self::encodeHeader($name) . " <$email>";
        return '"' . addcslashes($name, '"\\') . "\" <$email>";
    }

    /** Full parse: headers + flat list of leaf parts with decoded bodies. */
    public static function parse(string $raw): array
    {
        [$h, $b] = self::split($raw);
        $headers = self::parseHeaders($h);
        $parts = [];
        self::walk($headers, $b, '1', $parts, 0);
        return ['headers' => $headers, 'parts' => $parts];
    }

    private static function walk(array $headers, string $body, string $path, array &$out, int $depth): void
    {
        $ct = self::params(self::first($headers, 'content-type') ?: 'text/plain');
        $type = $ct['value'] ?: 'text/plain';
        if (str_starts_with($type, 'multipart/') && !empty($ct['boundary']) && $depth < 20) {
            foreach (self::splitMultipart($body, $ct['boundary']) as $k => $p) {
                [$ph, $pb] = self::split($p);
                self::walk(self::parseHeaders($ph), $pb, "$path." . ($k + 1), $out, $depth + 1);
            }
            return;
        }
        $enc = strtolower(trim(self::first($headers, 'content-transfer-encoding')));
        if ($enc === 'base64') $data = base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
        elseif ($enc === 'quoted-printable') $data = quoted_printable_decode($body);
        else $data = $body;

        $cd = self::params(self::first($headers, 'content-disposition'));
        $filename = $cd['filename'] ?? $ct['name'] ?? '';
        $cid = trim(self::first($headers, 'content-id'), " <>");
        $isText = ($type === 'text/plain' || $type === 'text/html');
        $isAttachment = !$isText || $cd['value'] === 'attachment';
        if ($isText && !$isAttachment) $data = self::toUtf8($data, $ct['charset'] ?? '');
        if ($type === 'message/rfc822' && $filename === '') $filename = 'message.eml';

        $out[] = [
            'path' => $path,
            'type' => $type,
            'filename' => $filename,
            'cid' => $cid,
            'attachment' => $isAttachment,
            'data' => $data,
        ];
    }

    private static function splitMultipart(string $body, string $boundary): array
    {
        $res = [];
        $segs = explode('--' . $boundary, $body);
        array_shift($segs); // preamble
        foreach ($segs as $s) {
            if (str_starts_with($s, '--')) break;
            $s = preg_replace('/^[ \t]*\r?\n/', '', $s);
            $s = preg_replace('/\r?\n$/', '', $s);
            $res[] = $s;
        }
        return $res;
    }
}
