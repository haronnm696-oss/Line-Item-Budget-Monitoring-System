<?php
$files = [
    __DIR__ . '/../frontend/src/components/Sidebar.jsx',
    __DIR__ . '/../frontend/src/pages/dashboards/ProgramLeaderDashboard.jsx',
    __DIR__ . '/../frontend/src/components/program/ApprovalInbox.jsx',
];
foreach ($files as $f) {
    $s = file_get_contents($f);
    echo basename($f), ' ', mb_check_encoding($s, 'UTF-8') ? 'valid utf8' : 'INVALID', PHP_EOL;
    foreach (['Updated', 'A�', '�?o', '—', '₱', 'Program overview'] as $needle) {
        $p = strpos($s, $needle);
        if ($p !== false) {
            echo '  ', $needle, ' @', $p, ' hex=', bin2hex(substr($s, $p, min(12, strlen($needle) + 2))), PHP_EOL;
        }
    }
}
