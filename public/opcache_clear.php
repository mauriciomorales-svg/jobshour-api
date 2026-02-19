<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo json_encode(['opcache' => 'cleared']);
} else {
    echo json_encode(['opcache' => 'not available']);
}
