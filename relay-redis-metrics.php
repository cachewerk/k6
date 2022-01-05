#!/usr/bin/env php
<?php

define('WP_SEARCH_PATHS', [
    '/var/www/html/wp-config.php',
    '/Users/michaelgrunder/.config/Sites/valet/wpmu'
]);

function getRedisCommandStats($cli) {
    $res = [];

    $data = $cli->info('commandstats');
    $re = '/^calls=([0-9]+).*/';

    foreach ($data as $cmd => $info) {
        $cmd = str_replace('cmdstat_', '', $cmd);
        if ( ! preg_match($re, $info, $matches) || count($matches) != 2) {
            echo "Warning:  '$info' doesn't match regex\n";
            continue;
        }
        $res[$cmd] = $matches[1];
    }

    return $res;
}

function panicAbort($reason) {
    echo json_encode(['status' => 'error', 'reason' => $reason], JSON_PRETTY_PRINT);
    exit(1);
}

function returnResult($data) {
    echo json_encode(['status' => 'success', 'data' => $data], JSON_PRETTY_PRINT);
    exit(0);
}

$opt = getopt('', ['host:', 'port:', 'unix-socket:', 'info', 'cmdstats']);
$host = $opt['host'] ?? NULL;
$port = $opt['port'] ?? 6379;
$sock = $opt['unix-socket'] ?? NULL;
$info = isset($opt['info']);
$cmdstats = isset($opt['cmdstats']);

if (!$info && !$cmdstats)
    panicAbort("Must pass either --info or --cmdstats");

try {
    if ($host) {
        $relay = new \Relay\Relay($host, $port);
    } else if ($sock) {
        $relay = new \Relay\Relay($sock);
    } else {
        panicAbort("Must pass either a host/port or a unix socket!");
    }
} catch (Exception $ex) {
    $connstr = $host ? "$host:$port" : $sock;
    panicAbort("Failed to connect to Redis @'$connstr' ({$ex->getMessage()})");
}

try {
    if ($info) {
        returnResult($relay->info());
    } else {
        returnResult(getRedisCommandStats($relay));
    }
} catch (Exception $ex) {
    panicAbort("Failed to get information '{$ex->getMessage()}'");
}

#function getRedisPath() {
#    foreach (WP_SEARCH_PATHS as $path) {
#        if ( ! file_exists("$path/wp-config.php"))
#            continue;
#
#        $data = file_get_contents("$path/wp-config.php");
#        if ( ! $data)
#            panicAbort("Failed to read '$path/wp-config.php'");
#
#        $str = strstr($data, 'WP_REDIS_CONFIG');
#        if ($str === false)
#            panicAbort("Failed to find WP_REDIS_CONFIG header");
#
#        $str = strstr($str, "'host'");
#        if ($str === false)
#            panicAbort("Failed to find 'host' line in WP_REDIS_CONFIG");
#
#        if ( ! preg_match("/.*'host'.+=>'([a-z/]+)'/", $str, $matches) || count($matches) != 2)
#            panicAbort("Failed to extract host");
#
#        if (strpos($matches[1], '/') === false)
#            panicAbort("Script only works with unix-socket host!");
#
#        return $matches[1];
#    }
#}
