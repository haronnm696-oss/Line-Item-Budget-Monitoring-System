<?php
$f = __DIR__ . '/database/seeders/DashboardDemoSeeder.php';
$s = file_get_contents($f);
$p = strpos($s, 'Realign');
echo substr($s, $p, 100), PHP_EOL;
echo bin2hex(substr($s, $p, 50)), PHP_EOL;
echo mb_check_encoding($s, 'UTF-8') ? 'valid utf8' : 'invalid utf8', PHP_EOL;
$l = strpos($s, 'Tranche 3');
echo substr($s, $l, 100), PHP_EOL;
echo bin2hex(substr($s, $l, 50)), PHP_EOL;
