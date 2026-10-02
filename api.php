<?php

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'feed';

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

try {
    if ($action === 'save_users') {
        $cfg = save_config(['targetUsers' => $body['targetUsers'] ?? '']);
        echo json_encode(['ok' => true, 'config' => public_config(), 'saved' => $cfg['targetUsers']]);
        exit;
    }
    if ($action === 'subscribe') {
        save_sub($body);
        $push = send_push_all('Telegram alerts ON', 'Rahul Dada, BAT9362, VIP7579, BTB0353, KBT6927 — naya bet aate hi yahan aayega.', 'tg-alerts-on');
        echo json_encode(['ok' => true, 'phones' => count(load_subs()), 'push' => $push]);
        exit;
    }
    if ($action === 'test') {
        $push = send_push_all('Test alert', 'Phone notification chal rahi hai. Bet aate hi aisi alert aayegi.', 'tg-test-' . time());
        echo json_encode(['ok' => true, 'push' => $push, 'phones' => count(load_subs())]);
        exit;
    }
    echo json_encode(poll_once(true));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
