<?php

function b64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($data, true);
    return $out === false ? '' : $out;
}

function der_len(int $len): string
{
    if ($len < 128) {
        return chr($len);
    }
    $bytes = ltrim(pack('N', $len), "\x00");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function p256_public_pem(string $point): string
{
    $oid = hex2bin('06072a8648ce3d0201');
    $curve = hex2bin('06082a8648ce3d030107');
    $algInner = $oid . $curve;
    $alg = "\x30" . der_len(strlen($algInner)) . $algInner;
    $bit = "\x00" . $point;
    $bitWrap = "\x03" . der_len(strlen($bit)) . $bit;
    $spki = "\x30" . der_len(strlen($alg . $bitWrap)) . $alg . $bitWrap;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function p256_private_pem(string $d, string $point): string
{
    $d = str_pad(substr($d, -32), 32, "\x00", STR_PAD_LEFT);
    $version = "\x02\x01\x01";
    $priv = "\x04\x20" . $d;
    $curve = hex2bin('06082a8648ce3d030107');
    $params = "\xa0" . der_len(strlen($curve)) . $curve;
    $bit = "\x00" . $point;
    $bitWrap = "\x03" . der_len(strlen($bit)) . $bit;
    $pub = "\xa1" . der_len(strlen($bitWrap)) . $bitWrap;
    $body = $version . $priv . $params . $pub;
    $seq = "\x30" . der_len(strlen($body)) . $body;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($seq), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

function ecdh_p256(string $privateD, string $localPoint, string $peerPoint): string
{
    $pem = p256_private_pem($privateD, $localPoint);
    $mine = openssl_pkey_get_private($pem);
    $peer = openssl_pkey_get_public(p256_public_pem($peerPoint));
    if (!$mine || !$peer) {
        throw new RuntimeException('Could not read push keys');
    }
    $secret = openssl_pkey_derive($peer, $mine, 32);
    if (!is_string($secret) || $secret === '') {
        throw new RuntimeException('Push key agreement failed');
    }
    return str_pad($secret, 32, "\x00", STR_PAD_LEFT);
}

function webpush_encrypt(string $plain, string $uaPublic, string $auth, string $asPrivate, string $asPublic, string $salt): string
{
    $shared = ecdh_p256($asPrivate, $asPublic, $uaPublic);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
    $tag = '';
    $cipher = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('Push encrypt failed');
    }
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
}

function openssl_config_path(): ?string
{
    $candidates = [
        (string) getenv('OPENSSL_CONF'),
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        'D:\\xampp\\php\\extras\\ssl\\openssl.cnf',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }
    return null;
}

function fresh_p256(): array
{
    $opts = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];
    $cnf = openssl_config_path();
    if ($cnf) {
        $opts['config'] = $cnf;
    }
    while (openssl_error_string()) {
    }
    $key = openssl_pkey_new($opts);
    if (!$key) {
        $errs = [];
        while ($err = openssl_error_string()) {
            $errs[] = $err;
        }
        throw new RuntimeException('Could not create notification key' . ($errs ? ': ' . implode('; ', $errs) : ''));
    }
    $details = openssl_pkey_get_details($key);
    $d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
    $point = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $pem = p256_private_pem($d, $point);
    if (!openssl_pkey_get_private($pem)) {
        throw new RuntimeException('Could not store notification key');
    }
    return ['d' => $d, 'public' => $point, 'pem' => $pem];
}

function der_sig_to_raw(string $der): string
{
    $pos = 0;
    if (ord($der[$pos]) !== 0x30) {
        throw new RuntimeException('Bad signature');
    }
    $pos++;
    $len = ord($der[$pos]);
    $pos++;
    if ($len & 0x80) {
        $n = $len & 0x7f;
        $pos += $n;
    }
    if (ord($der[$pos]) !== 0x02) {
        throw new RuntimeException('Bad signature R');
    }
    $pos++;
    $rLen = ord($der[$pos]);
    $pos++;
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;
    if (ord($der[$pos]) !== 0x02) {
        throw new RuntimeException('Bad signature S');
    }
    $pos++;
    $sLen = ord($der[$pos]);
    $pos++;
    $s = substr($der, $pos, $sLen);
    $r = substr(ltrim($r, "\x00"), -32);
    $s = substr(ltrim($s, "\x00"), -32);
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

function ensure_vapid(): array
{
    $keys = read_json('vapid.json', []);
    if (!empty($keys['publicKey']) && !empty($keys['privatePem'])) {
        return $keys;
    }
    $made = fresh_p256();
    $keys = [
        'publicKey' => b64url_encode($made['public']),
        'privatePem' => $made['pem'],
        'createdAt' => date('c'),
    ];
    write_json('vapid.json', $keys);
    return $keys;
}

function load_subs(): array
{
    $data = read_json('subs.json', ['subs' => []]);
    return is_array($data['subs'] ?? null) ? $data['subs'] : [];
}

function save_sub(array $sub): void
{
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
    $auth = (string) ($sub['keys']['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return;
    }
    $subs = [];
    foreach (load_subs() as $row) {
        if (($row['endpoint'] ?? '') !== $endpoint) {
            $subs[] = $row;
        }
    }
    $subs[] = [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
        'savedAt' => date('c'),
    ];
    write_json('subs.json', ['subs' => array_slice($subs, -20)]);
}

function vapid_header(string $endpoint, array $vapid, string $subject): string
{
    $parts = parse_url($endpoint);
    $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    $header = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = b64url_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    ]));
    $signing = $header . '.' . $payload;
    $der = '';
    if (!openssl_sign($signing, $der, $vapid['privatePem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign notification');
    }
    return 'vapid t=' . $signing . '.' . b64url_encode(der_sig_to_raw($der)) . ',k=' . $vapid['publicKey'];
}

function post_push(string $endpoint, string $body, string $authorization): array
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 120',
            'Urgency: high',
            'Authorization: ' . $authorization,
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($resp) ? $resp : '', 'error' => $err];
}

function send_push_all(string $title, string $bodyText, string $tag): array
{
    $subs = load_subs();
    if (!$subs) {
        return ['sent' => 0, 'failed' => 0, 'reason' => 'no phones subscribed'];
    }
    $cfg = load_config();
    $vapid = ensure_vapid();
    $payload = json_encode([
        'title' => $title,
        'body' => $bodyText,
        'tag' => $tag,
        'url' => './',
    ], JSON_UNESCAPED_UNICODE);
    $sent = 0;
    $failed = 0;
    $keep = [];
    foreach ($subs as $row) {
        try {
            $ua = b64url_decode($row['keys']['p256dh']);
            $auth = b64url_decode($row['keys']['auth']);
            $ephemeral = fresh_p256();
            $encrypted = webpush_encrypt($payload, $ua, $auth, $ephemeral['d'], $ephemeral['public'], random_bytes(16));
            $authz = vapid_header($row['endpoint'], $vapid, $cfg['subject']);
            $res = post_push($row['endpoint'], $encrypted, $authz);
            $code = $res['code'];
            if ($code >= 200 && $code < 300) {
                $sent++;
                $keep[] = $row;
                continue;
            }
            $failed++;
            log_line('push fail ' . $code . ' ' . substr($res['body'] ?: $res['error'], 0, 180));
            if (!in_array($code, [404, 410], true)) {
                $keep[] = $row;
            }
        } catch (Throwable $e) {
            $failed++;
            log_line('push error ' . $e->getMessage());
            $keep[] = $row;
        }
    }
    write_json('subs.json', ['subs' => $keep]);
    return ['sent' => $sent, 'failed' => $failed];
}
