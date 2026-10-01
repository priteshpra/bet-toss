<?php

function with_poll_lock(callable $fn)
{
    $fp = fopen(data_path('poll.lock'), 'c');
    if ($fp === false) {
        return $fn();
    }
    try {
        flock($fp, LOCK_EX);
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function ist_clock(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('h:i:s A') . ' IST';
}

function load_bets(): array
{
    $data = read_json('bets.json', ['posts' => []]);
    return is_array($data['posts'] ?? null) ? $data['posts'] : [];
}

function watched_only(array $posts, array $targets): array
{
    $rows = [];
    foreach ($posts as $p) {
        if (($p['type'] ?? '') !== 'BET_PLACED') {
            continue;
        }
        if (!user_is_target((string) ($p['userName'] ?? ''), $targets, (string) ($p['rawText'] ?? ''))) {
            continue;
        }
        $rows[] = $p;
    }
    usort($rows, fn($a, $b) => strcmp($b['isoTime'] ?? '', $a['isoTime'] ?? ''));
    return array_slice($rows, 0, 80);
}

function poll_once(bool $notify): array
{
    return with_poll_lock(function () use ($notify) {
        $cfg = load_config();
        $targets = $cfg['targetUsers'];
        $storedPack = read_json('bets.json', ['posts' => [], 'updatedAt' => '']);
        $updatedAt = strtotime((string) ($storedPack['updatedAt'] ?? '')) ?: 0;
        if ($updatedAt > 0 && (time() - $updatedAt) < 2 && !empty($storedPack['posts'])) {
            $watched = watched_only($storedPack['posts'], $targets);
            return [
                'ok' => true,
                'status' => 'connected',
                'fetchedAt' => ist_clock(),
                'config' => public_config(),
                'watched' => $watched,
                'fresh' => [],
                'push' => ['sent' => 0, 'failed' => 0],
                'vapidPublicKey' => ensure_vapid()['publicKey'],
                'phones' => count(load_subs()),
                'watcherAgo' => watcher_age(),
                'tunnelUrl' => tunnel_url(),
            ];
        }
        $stored = load_bets();
        $known = [];
        foreach ($stored as $p) {
            if (!empty($p['postId'])) {
                $known[$p['postId']] = true;
            }
        }
        $fetched = fetch_channel_posts($cfg['channel'], $known);
        $byId = [];
        foreach ($stored as $p) {
            if (!empty($p['postId'])) {
                $byId[$p['postId']] = $p;
            }
        }
        foreach ($fetched['posts'] as $p) {
            if (!empty($p['postId'])) {
                $byId[$p['postId']] = $p;
            }
        }
        $all = array_values($byId);
        usort($all, fn($a, $b) => strcmp($b['isoTime'] ?? '', $a['isoTime'] ?? ''));
        $keepWatched = [];
        $keepRest = [];
        foreach ($all as $p) {
            if (($p['type'] ?? '') === 'BET_PLACED' && user_is_target((string) ($p['userName'] ?? ''), $targets, (string) ($p['rawText'] ?? ''))) {
                $keepWatched[] = $p;
            } else {
                $keepRest[] = $p;
            }
        }
        $all = array_merge($keepWatched, array_slice($keepRest, 0, 120));
        write_json('bets.json', ['posts' => $all, 'updatedAt' => date('c')]);

        $watched = watched_only($all, $targets);
        $seen = read_json('seen.json', ['primed' => false, 'ids' => []]);
        $ids = array_fill_keys($seen['ids'] ?? [], true);
        $fresh = [];
        foreach ($watched as $p) {
            $id = (string) ($p['postId'] ?? '');
            if ($id === '' || isset($ids[$id])) {
                continue;
            }
            $fresh[] = $p;
            $ids[$id] = true;
        }
        $primed = !empty($seen['primed']);
        write_json('seen.json', [
            'primed' => true,
            'ids' => array_slice(array_keys($ids), -800),
        ]);

        $alertable = [];
        $cutoff = time() - 600;
        $pushed = ['sent' => 0, 'failed' => 0];
        if ($primed) {
            foreach (array_reverse($fresh) as $bet) {
                $ts = strtotime((string) ($bet['isoTime'] ?? '')) ?: 0;
                if ($ts < $cutoff) {
                    continue;
                }
                $alertable[] = $bet;
                if (!$notify) {
                    continue;
                }
                $title = ($bet['userName'] ?: 'Watched user') . ' ka naya bet';
                $body = 'Team: ' . ($bet['teamName'] ?: '—') . '  |  Amount: ' . ($bet['amount'] ?: '—');
                $result = send_push_all($title, $body, (string) $bet['postId']);
                $pushed['sent'] += $result['sent'];
                $pushed['failed'] += $result['failed'];
                log_line('NEW ' . $title . ' ' . $body . ' push=' . $result['sent']);
            }
        } else {
            log_line('primed ' . count($watched) . ' old bets, no alert');
        }

        return [
            'ok' => true,
            'status' => $fetched['ok'] ? 'connected' : ($watched ? 'cached' : 'error'),
            'fetchedAt' => ist_clock(),
            'config' => public_config(),
            'watched' => $watched,
            'fresh' => $alertable,
            'push' => $pushed,
            'vapidPublicKey' => ensure_vapid()['publicKey'],
            'phones' => count(load_subs()),
            'watcherAgo' => watcher_age(),
            'tunnelUrl' => tunnel_url(),
        ];
    });
}
