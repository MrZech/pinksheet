<?php
if ($argc < 2) {
    fwrite(STDERR, "Usage: php check_db.php <path> [label]\n");
    exit(4);
}
$path = $argv[1];
$label = $argv[2] ?? 'db';
try {
    $db = new SQLite3($path);
    $res = $db->querySingle('PRAGMA integrity_check;');
    if ($res !== 'ok') {
        fwrite(STDERR, "$label integrity_check failed: $res\n");
        exit(2);
    }
} catch (Exception $e) {
    fwrite(STDERR, "$label integrity_check error: " . $e->getMessage() . "\n");
    exit(3);
}
exit(0);
