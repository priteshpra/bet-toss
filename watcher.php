<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';

log_line('watcher started');
while (true) {
    touch_watcher();
    try {
        $pack = poll_once(true);
        $n = count($pack['fresh'] ?? []);
        if ($n > 0) {
            log_line('alerted ' . $n);
        }
    } catch (Throwable $e) {
        log_line('error ' . $e->getMessage());
    }
    sleep(4);
}
