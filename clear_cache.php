<?php
// OPcache & APCu Clear Helper
header("Content-Type: text/plain");
header("Cache-Control: no-cache, no-store, must-revalidate");

$res = [];
if (function_exists('opcache_reset')) {
    $res['opcache'] = @opcache_reset() ? 'CLEARED' : 'FAILED';
} else {
    $res['opcache'] = 'NOT_AVAILABLE';
}

if (function_exists('apcu_clear_cache')) {
    $res['apcu'] = @apcu_clear_cache() ? 'CLEARED' : 'FAILED';
} else {
    $res['apcu'] = 'NOT_AVAILABLE';
}

echo "OWIPI Cache Status:\n";
print_r($res);
echo "\nTimestamp: " . date('Y-m-d H:i:s') . "\n";
