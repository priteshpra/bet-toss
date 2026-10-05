<?php
$rate = 44100;
$knocks = 3;
$gap = 0.42;
$tail = 0.15;
$total = (int) (($knocks - 1) * $gap * $rate + 0.28 * $rate + $tail * $rate);
$pcm = array_fill(0, $total, 0);
for ($k = 0; $k < $knocks; $k++) {
    $start = (int) ($k * $gap * $rate);
    $len = (int) (0.28 * $rate);
    for ($i = 0; $i < $len; $i++) {
        $t = $i / $rate;
        $env = exp(-$t * 14);
        $noise = (mt_rand(-32767, 32767) / 32767) * exp(-$t * 55);
        $body = sin(2 * M_PI * 110 * $t) * 0.95 + sin(2 * M_PI * 220 * $t) * 0.45 + sin(2 * M_PI * 55 * $t) * 0.7;
        $v = ($body * $env + $noise * 0.85) * 32767;
        $idx = $start + $i;
        if ($idx >= $total) {
            break;
        }
        $pcm[$idx] = max(-32767, min(32767, (int) $v));
    }
}
$data = '';
foreach ($pcm as $sample) {
    $data .= pack('v', $sample & 0xFFFF);
}
$dataLen = strlen($data);
$hdr = 'RIFF' . pack('V', 36 + $dataLen) . 'WAVE';
$hdr .= 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16);
$hdr .= 'data' . pack('V', $dataLen);
$dir = __DIR__ . '/app/src/main/res/raw';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$path = $dir . '/bet_knock.wav';
file_put_contents($path, $hdr . $data);
echo 'bytes=' . filesize($path) . PHP_EOL;
