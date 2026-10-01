<?php

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$key = (string) ($_GET['key'] ?? '');
$want = (string) (load_config()['cronKey'] ?? '');
if ($want === '' || strlen($key) !== strlen($want) || !hash_equals($want, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
touch_watcher();
echo json_encode(poll_once(true));
