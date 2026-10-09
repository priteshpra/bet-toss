<?php

function tg_http_get(string $url, int $timeout = 18): ?string
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400 || $body === '') {
        return null;
    }
    return $body;
}

function tg_norm_name(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
}

function canonical_tg_user(?string $name): string
{
    $raw = trim((string) $name);
    if ($raw === '') {
        return $raw;
    }
    $n = tg_norm_name($raw);
    $map = [
        'rahuldada' => 'Rahul Dada',
        'rahuldadaa' => 'Rahul Dada',
        'rahuldada1' => 'Rahul Dada',
        'bat9362' => 'BAT9362',
        'vip7579' => 'VIP7579',
        'stb3533' => 'STB3533',
        'kbt6927' => 'KBT6927',
    ];
    return $map[$n] ?? $raw;
}

function parse_amount_number(?string $raw): float
{
    if (!$raw) {
        return 0.0;
    }
    $n = preg_replace('/[^0-9.]/', '', $raw);
    return $n === '' ? 0.0 : (float) $n;
}

function clean_emoji_and_symbols(string $s): string
{
    $s = preg_replace('/^[\s\p{So}\p{Sk}\p{Sm}\p{Cs}\p{Cn}\p{Co}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}#*@|~`!%^&()_=+[{\]};:\'\"<>,\/?\\-]+/u', '', $s);
    $s = preg_replace('/[\s\p{So}\p{Sk}\p{Sm}\p{Cs}\p{Cn}\p{Co}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}#*@|~`!%^&()_=+[{\]};:\'\"<>,\/?\\-]+$/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function parse_tg_emoji_bet(string $rawText, array $targets = []): ?array
{
    $firstLine = trim(preg_split('/\R/', $rawText)[0] ?? $rawText);
    $cleaned = clean_emoji_and_symbols($firstLine);

    if (!preg_match('/^(.*?)\s+(₹\s*[\d,]+(?:\.\d+)?|Rs\.?\s*[\d,]+|[\d,]{2,})$/iu', $cleaned, $match)) {
        return null;
    }

    $front = trim($match[1]);
    $amt = trim($match[2]);

    if (preg_match('/^(.*?)\s+(deposit|withdrawal|withdraw)$/iu', $front, $dm)) {
        return [
            'userName' => trim($dm[1]),
            'teamName' => null,
            'amount' => $amt,
            'action' => strtolower($dm[2]),
            'type' => 'DEPOSIT_WITHDRAWAL',
        ];
    }

    $u = null;
    $t = null;

    if (empty($targets) && function_exists('load_config')) {
        $targets = (array) (load_config()['targetUsers'] ?? []);
    }

    foreach ($targets as $target) {
        $target = trim((string) $target);
        if ($target === '') {
            continue;
        }
        if (stripos($front, $target) === 0) {
            $rest = trim(substr($front, strlen($target)));
            if ($rest !== '') {
                $u = $target;
                $t = $rest;
                break;
            }
        }
    }

    if (!$u && preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]{2,40})\s+(.+)$/u', $front, $fm)) {
        $u = trim($fm[1]);
        $t = trim($fm[2]);
    }

    if (!$u || !$t) {
        return null;
    }

    $t = preg_replace('/^(team\s*[:\-]|[:\-])\s*/i', '', $t);
    $t = trim($t, " :-–—\t");

    if (preg_match('/^(BETFAIR|SHEDULE|SCHEDULE|SUPPORT|LOAD|MATCHES|BONUS)$/iu', $u)) {
        return null;
    }

    return [
        'userName' => $u,
        'teamName' => $t,
        'amount' => $amt,
        'action' => 'BET_PLACED',
        'type' => 'BET_PLACED',
    ];
}

function parse_tg_fields(string $rawText, array $targets = []): array
{
    $userName = null;
    $teamName = null;
    $amount = null;
    $action = null;
    $type = 'ANNOUNCEMENT';

    if (preg_match('/USER\s*NAME\s*[-:]\s*([^\n\r]+)/i', $rawText, $m)) {
        $userName = trim($m[1]);
    }
    if (preg_match('/TEAM\s*NAME\s*[-:]\s*([^\n\r]+)/i', $rawText, $m)) {
        $teamName = trim($m[1]);
        $type = 'BET_PLACED';
    }
    if (preg_match('/AMOUNT\s*[-:]\s*([^\n\r]+)/i', $rawText, $m)) {
        $amount = trim($m[1]);
    }
    if (preg_match('/DEPOSIT\/WITHDRAWAL\s*[-:]\s*([^\n\r]+)/i', $rawText, $m)) {
        $action = trim($m[1]);
        $type = 'DEPOSIT_WITHDRAWAL';
    }

    $isAnnouncement = (bool) preg_match('/TOSS LOAD|TOSS ID LIST|UPCOMING MATCHES|BONUS\s+\d|WON THE TOSS|BETS SETTLED|FASTEST SETTLEMENT|Live Support|TOSS SHEDULE|TOSS SCHEDULE/i', $rawText);

    if ($teamName === null && !$isAnnouncement) {
        $emojiLine = parse_tg_emoji_bet($rawText, $targets);
        if ($emojiLine) {
            $userName = $emojiLine['userName'];
            $teamName = $emojiLine['teamName'];
            $amount = $emojiLine['amount'];
            $action = $emojiLine['action'];
            $type = $emojiLine['type'];
        } else {
            $first = trim(preg_split('/\R/', $rawText)[0] ?? $rawText);
            $user = '';
            $rest = '';
            if (preg_match('/^(.+?)  +(.+)$/', $first, $parts)) {
                $user = trim($parts[1]);
                $rest = trim($parts[2]);
            } elseif (preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]{2,40}) (.+)$/', $first, $parts)) {
                $user = trim($parts[1]);
                $rest = trim($parts[2]);
            }
            if ($user !== '' && preg_match('/^(.+?) [-:] (.+)$/', $rest, $tm) && preg_match('/\d/', $tm[2] ?? '')) {
                $userName = $user;
                $teamName = trim($tm[1]);
                $amount = trim($tm[2]);
                $type = 'BET_PLACED';
            }
        }
    }
    if (!$teamName && $userName && preg_match('/TOSS|WINNER|BET/i', $rawText) && !$isAnnouncement) {
        $type = 'BET_PLACED';
    }
    if (preg_match('/UPCOMING MATCHES|TOSS ID LIST/i', $rawText)) {
        $type = 'SCHEDULE';
    }

    return [
        'userName' => canonical_tg_user($userName),
        'teamName' => $teamName,
        'amount' => $amount,
        'amountValue' => parse_amount_number($amount),
        'action' => $action ?: ($type === 'BET_PLACED' ? 'BET_PLACED' : 'UPDATE'),
        'type' => $type,
    ];
}

function user_is_target(string $userName, array $targets, string $rawText = ''): bool
{
    $u = tg_norm_name($userName);
    $hay = tg_norm_name($userName . ' ' . $rawText);
    foreach ($targets as $t) {
        $n = tg_norm_name((string) $t);
        if ($n === '') {
            continue;
        }
        if ($n === 'rahuldada' || $n === 'rahul') {
            if ($u === 'rahuldada' || str_contains($hay, 'rahuldada')) {
                return true;
            }
            if ($n === 'rahul') {
                continue;
            }
        }
        if ($u === $n || ($n !== 'rahul' && (str_contains($u, $n) || (str_contains($n, $u) && strlen($u) >= 5)))) {
            return true;
        }
        if (str_contains($hay, $n)) {
            return true;
        }
    }
    return false;
}

function parse_tg_html(string $html, string $channel): array
{
    $blocks = preg_split('/<div class="tgme_widget_message_wrap/', $html);
    $posts = [];
    for ($i = 1, $len = count($blocks); $i < $len; $i++) {
        $block = $blocks[$i];
        if (!preg_match('/data-post="([^"]+)"/', $block, $pm)) {
            continue;
        }
        $postId = $pm[1];
        $iso = date('c');
        if (preg_match('/<time[^>]*datetime="([^"]+)"/', $block, $tm)) {
            $iso = $tm[1];
        }
        $display = $iso;
        try {
            $display = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('h:i:s A');
        } catch (Throwable $e) {
        }
        if (!preg_match('/<div class="tgme_widget_message_text[^"]*"[^>]*>([\s\S]*?)<\/div>/', $block, $tx)) {
            continue;
        }
        $rawText = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $tx[1])), ENT_QUOTES | ENT_HTML5));
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $rawText);
        if (is_string($fixed) && $fixed !== '') {
            $rawText = $fixed;
        }
        if ($rawText === '') {
            continue;
        }
        $fields = parse_tg_fields($rawText);
        $posts[] = [
            'postId' => $postId,
            'isoTime' => $iso,
            'displayTime' => $display,
            'rawText' => $rawText,
            'userName' => $fields['userName'] ?: 'Anonymous',
            'teamName' => $fields['teamName'],
            'amount' => $fields['amount'],
            'amountValue' => $fields['amountValue'],
            'action' => $fields['action'],
            'type' => $fields['type'],
            'channel' => '@' . $channel,
            'messageUrl' => 'https://t.me/' . $postId,
        ];
    }
    return $posts;
}

function fetch_channel_posts(string $channel, array $known = []): array
{
    $fresh = [];
    $seen = [];
    $before = null;
    $ok = false;
    $hitKnown = false;
    for ($page = 0; $page < 5; $page++) {
        $url = 'https://t.me/s/' . rawurlencode($channel) . ($before ? ('?before=' . rawurlencode((string) $before)) : '');
        $html = tg_http_get($url, $page === 0 ? 18 : 12);
        if (!$html) {
            break;
        }
        $ok = true;
        $rows = parse_tg_html($html, $channel);
        if (!$rows) {
            break;
        }
        $oldest = null;
        foreach ($rows as $p) {
            $id = (string) ($p['postId'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $fresh[] = $p;
            if (isset($known[$id])) {
                $hitKnown = true;
            }
            if (preg_match('/\/(\d+)$/', $id, $nm)) {
                $n = (int) $nm[1];
                $oldest = $oldest === null ? $n : min($oldest, $n);
            }
        }
        if ($hitKnown || $oldest === null) {
            break;
        }
        $before = $oldest;
    }
    return ['ok' => $ok, 'posts' => $fresh];
}
