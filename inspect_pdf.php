<?php
$f = __DIR__ . '/test_output.pdf';
if (!file_exists($f)) { echo "MISSING\n"; exit(1); }
$d = file_get_contents($f);
echo "SIZE: " . strlen($d) . "\n";
echo "HEAD (raw): " . substr($d, 0, 16) . "\n";
echo "HEX DUMP:\n";
for ($i = 0; $i < min(256, strlen($d)); $i++) {
    printf('%02X ', ord($d[$i]));
    if ((($i + 1) % 16) == 0) echo "\n";
}
echo "\n";
?>