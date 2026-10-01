<?php

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/poll.php';

function ensure_icons(): void
{
    $icon = dirname(__DIR__) . '/icon-192.png';
    if (is_file($icon)) {
        return;
    }
    write_coin_icon(dirname(__DIR__) . '/icon-192.png', 192);
    write_coin_icon(dirname(__DIR__) . '/icon-512.png', 512);
}

function png_chunk(string $type, string $data): string
{
    $crc = crc32($type . $data);
    if ($crc < 0) {
        $crc += 4294967296;
    }
    return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
}

function write_coin_icon(string $path, int $size): void
{
    $raw = '';
    $cx = ($size - 1) / 2;
    $cy = ($size - 1) / 2;
    $outer = $size * 0.46;
    $inner = $size * 0.34;
    for ($y = 0; $y < $size; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $size; $x++) {
            $d = hypot($x - $cx, $y - $cy);
            if ($d <= $inner) {
                $raw .= "\x12\x18\x28";
            } elseif ($d <= $outer) {
                $raw .= "\xf5\xc4\x2a";
            } else {
                $raw .= "\x07\x0b\x14";
            }
        }
    }
    $png = "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
        . png_chunk('IDAT', zlib_encode($raw, ZLIB_ENCODING_DEFLATE, 6))
        . png_chunk('IEND', '');
    file_put_contents($path, $png);
}

ensure_icons();
