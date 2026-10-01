<?php

function data_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function data_path(string $file): string
{
    return data_dir() . DIRECTORY_SEPARATOR . $file;
}

function read_json(string $file, array $fallback): array
{
    $path = data_path($file);
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? $data : $fallback;
}

function write_json(string $file, array $data): void
{
    $path = data_path($file);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $json);
    if (!@rename($tmp, $path)) {
        file_put_contents($path, $json, LOCK_EX);
        @unlink($tmp);
    }
}

function default_watch_users(): array
{
    return ['Rahul Dada', 'BAT9362', 'VIP7579', 'BTB0353', 'KBT6927'];
}

function load_config(): array
{
    $cfg = read_json('config.json', []);
    $base = [
        'pin' => '2565',
        'cronKey' => 'tgbet-cron-9362',
        'channel' => 'BetfairTossbookOrignal',
        'targetUsers' => default_watch_users(),
        'subject' => 'mailto:tg-bet-alert@localhost',
    ];
    $merged = array_merge($base, $cfg);
    $pin = getenv('APP_PIN');
    if (is_string($pin) && $pin !== '') {
        $merged['pin'] = $pin;
    }
    return $merged;
}

function save_config(array $cfg): array
{
    $current = load_config();
    if (isset($cfg['targetUsers'])) {
        $users = is_array($cfg['targetUsers']) ? $cfg['targetUsers'] : preg_split('/,/', (string) $cfg['targetUsers']);
        $current['targetUsers'] = array_values(array_filter(array_map('trim', $users ?: [])));
        if (!$current['targetUsers']) {
            $current['targetUsers'] = default_watch_users();
        }
    }
    write_json('config.json', $current);
    return $current;
}

function public_config(): array
{
    $cfg = load_config();
    return [
        'channel' => $cfg['channel'],
        'targetUsers' => $cfg['targetUsers'],
        'webUrl' => 'https://web.telegram.org/k/#@' . $cfg['channel'],
    ];
}

function pin_ok(?string $got): bool
{
    $pin = (string) (load_config()['pin'] ?? '');
    $got = (string) $got;
    if ($pin === '' || strlen($got) !== strlen($pin)) {
        return false;
    }
    return hash_equals($pin, $got);
}

function tunnel_url(): ?string
{
    $log = data_path('tunnel.log');
    if (is_file($log)) {
        $text = file_get_contents($log);
        if (is_string($text) && preg_match_all('#https://[a-z0-9-]+\.trycloudflare\.com#i', $text, $m) && !empty($m[0])) {
            $url = (string) end($m[0]);
            write_json('tunnel.json', ['url' => $url, 'seenAt' => date('c')]);
            return $url;
        }
    }
    $saved = read_json('tunnel.json', []);
    return !empty($saved['url']) ? (string) $saved['url'] : null;
}

function watcher_age(): ?int
{
    $state = read_json('watcher.json', []);
    if (empty($state['ts'])) {
        return null;
    }
    return max(0, time() - (int) $state['ts']);
}

function touch_watcher(): void
{
    write_json('watcher.json', ['ts' => time(), 'at' => date('c')]);
}

function log_line(string $msg): void
{
    $path = data_path('watcher.log');
    file_put_contents($path, date('H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
    if (is_file($path) && filesize($path) > 400000) {
        $lines = array_slice(file($path) ?: [], -80);
        file_put_contents($path, implode('', $lines));
    }
}
