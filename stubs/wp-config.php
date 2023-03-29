<?php

/**
 * Object Cache Pro configuration example.
 */
define('WP_REDIS_CONFIG', [
    'token' => '...',
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 0,
    // 'password' => null,
    'timeout' => 0.5,
    'read_timeout' => 0.5,
    'retries' => 3,
    'prefetch' => true,
    'serializer' => 'igbinary',
    'split_alloptions' => true,
    'debug' => false,
    'save_commands' => false,
]);

/**
 * Relay + Object Cache Pro configuration example.
 */
define('WP_REDIS_CONFIG', [
    'token' => '...',
    'host' => '127.0.0.1',
    'port' =>  6379,
    'database' => 0,
    // 'password' => null,
    'timeout' => 0.5,
    'read_timeout' => 0.5,
    'retries' => 3,
    'client' => 'relay',
    'prefetch' => false,
    'serializer' => 'igbinary',
    'compression' => 'zstd',
    'split_alloptions' => false,
    'debug' => false,
    'save_commands' => false,
]);
