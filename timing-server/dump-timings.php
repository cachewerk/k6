<?php

$redis = new Redis;
$redis->connect('localhost', 6379);

function sql_escape_literal(string $s): string {
    // Only required escaping: single quotes and backslashes
    return str_replace(
        ["\\", "'"],
        ["\\\\", "''"],
        $s
    );
}

function drainList($redis, $list) {
    $result = [];

    while (($v = $redis->lPop($list)) !== false) {
        $v = (int)$v;
        if ( ! $v)
            continue;

        $result[] = $v;

    }

    return $result;
}

function getList($redis, $list) {
    return $redis->lRange($list, 0, -1);
}

$opt = getopt('cd', ['drain']);
$drain = isset($opt['d']) || isset($opt['drain']);
$count = isset($opt['c']);

$counts = [];

foreach ($redis->keys('raw:*') as $key) {
    $url = substr($key, 4);

    if ($count) {
        $counts[$url] = $redis->lLen($key);
        continue;
    }

    $entries = $drain ? drainList($redis, $key) : getList($redis, $key);

    foreach ($entries as $millis) {
        $millis = (int)$millis;
        if (!$millis) {
            fprintf(STDERR, "Skipping URL: %s with millis: %d\n", $url, $millis);
            continue;
        }

        $escapedUrl = sql_escape_literal($url);

        printf(
            "INSERT INTO `bjsrawpetfood`.`timings` (url, millis) VALUES ('%s', %d);\n",
            $escapedUrl,
            $millis
        );
    }
}

if ($count) {
    $total = array_sum($counts);
    arsort($counts);
    foreach ($counts as $url => $count) {
        printf("%s: %d\n", $url, $count, ($count / $total) * 100);
    }
}
