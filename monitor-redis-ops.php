<?php
require_once('utils.php');

$signaled = 0;

function sigintHandler($signo) {
    global $signaled;
    $signaled = 1;
}

function findDefine($lines, $define) {
    $search = "define('$define'";

    foreach ($lines as $i => $line) {
        if (strpos($line, $search) !== false)
            return $i;
    }

    panicAbort("Can't find define '$define'");
}

function getOCPConfigEx($filename) {
    $arr = explode("\n", file_get_contents($filename));
    $start = $end = NULL;
    foreach ($arr as $l => $line) {
        if (strpos($line, "WP_REDIS_CONFIG") !== false)
            $start = $l;
        else if ($l !== NULL && substr($line, 0, 3) === ']);')
            $end = $l;
    }
    if ($start === NULL || $end === NULL)
        panicAbort("Can't find config start/end");

    $script = "";
    for ($i = $start; $i <= $end; $i++) {
        $script .= $arr[$i] . "\n";
    }

    $script = str_replace("define('WP_REDIS_CONFIG', [", 'return [', $script);
    $script = str_replace("]);", "];", $script);

    $wp_redis_config = eval($script);
    if ( ! is_array($wp_redis_config))
        panicAbort("Couldn't get OCP settings!");

    $cron_line = findDefine($arr, 'DISABLE_WP_CRON');
    $disabled = strpos($arr[$cron_line], 'true') !== false;

    return ['redis' => $wp_redis_config, 'cron' => $disabled];
}

function clearScreen() {
    echo chr(27).chr(91).'H'.chr(27).chr(91).'J';   //^[H^[J
}

function getOpsPerSec($redis) {
    $info = $redis->info();
    return $info['instantaneous_ops_per_sec'];
}

function getUsage($redis) {
    $info = $redis->info();
    return [
        $info['used_memory_human'],
        $info['used_memory_peak_human']
    ];
}
function getRedisOs($redis) {
    $info = $redis->info();
    return $info['os'];
}

function getCommandCounts($redis) {
    $result = [];

    $cmds = $redis->info('commandstats');

    foreach ($cmds as $cmd => $info) {
        if ( ! preg_match('/calls=([0-9]+),/', $info, $matches) || count($matches) != 2)
            continue;

        $cmd = strtoupper(explode('_', $cmd)[1]);
        $result[$cmd] = $matches[1];
    }

    return $result;
}

function diffCommandCounts($old, $new, &$neg = 0) {
    $result = [];

    $neg = 0;

    $keys = allKeys($old, $new);
    foreach ($keys as $cmd) {
        $result[$cmd] = ($new[$cmd] ?? 0) - ($old[$cmd] ?? 0);
        $neg |= $result[$cmd] < 0;
    }

    return array_filter($result);
}

function getMaxLen($min, ...$arr) {
    $maxlen = $min;
    foreach ($arr as $a) {
        foreach (array_keys($a) as $k) {
            if (strlen($k) > $maxlen) $maxlen = strlen($k);
        }
    }

    return $maxlen;
}

function allKeys($arr1, $arr2, $arr3 = []) {
    return array_unique(array_merge(array_keys($arr1), array_keys($arr2), array_keys($arr3)));
}

function getLine($key, $len, $col1, $col2) {
    $col1 = is_numeric($col1) ? number_format($col1) : $col1;
    $col2 = is_numeric($col2) ? number_format($col2) : $col2;

    return str_pad(strtoupper(trim($key)), $len) . ' ' .
           str_pad($col1, 12, ' ', STR_PAD_LEFT) . ' ' .
           str_pad($col2, 12, ' ', STR_PAD_LEFT) . "\n";
}

function printLine($key, $len, $col1, $col2) {
    echo getLine($key, $len, $col1, $col2);
}

function getTotalLine($hash, $hlen, $cmd, $cmdlen, $count, $countlen) {
    return str_pad($hash, $hlen) . ' ' . str_pad($cmd, $cmdlen) . ' ' .
           str_pad(number_format($count), $countlen, ' ', STR_PAD_LEFT) . "\n";

}

function printTotalLine($hash, $hlen, $cmd, $cmdlen, $count, $countlen) {
    echo getTotalLine($hash, $hlen, $cmd, $cmdlen, $count, $countlen);
}

function getLens($totals) {
    $hlen = $nlen = 0;
    $cmdlen = strlen('TOTAL');

    foreach ($totals as $hash => $stats) {
        if (strlen($hash) > $hlen) $hlen = strlen($hash);
        foreach ($stats as $cmd => $count) {
            $count = number_format($count);
            if (strlen($cmd) > $cmdlen) $cmdlen = strlen($cmd);
            if (strlen($count) > $nlen) $nlen = strlen($count);
        }
    }

    return [$hlen, $cmdlen, $nlen];
}

function getOCPHostPort($url) {
    $re = '/.*@([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+):([0-9]+)/';
    if ( ! preg_match($re, $url, $matches) || count($matches) != 3)
        die("Error:  Can't parse Redis URL '$url'\n");
    return [$matches[1], $matches[2]];
}

$opt = getopt('', ['sleep:', 'site-url:', 'user:', 'pass:']);
$site_url = getSiteUrlOpt($opt);
$user = $opt['user'] ?? NULL;
$pass = $opt['pass'] ?? NULL;

if (! $site_url) panicAbort($argv[0] . " Must pass a site url");

if ($site_url == 'local') {
    if (getOSType() == 'Darwin') {
        $site_url = 'bjsrawpetfood.test';
    } else {
        $site_url = 'cthulhu';
    }
} else if ($site_url == 'remote') {
    if (getOSType() == 'Darwin') {
        $site_url = 'cthulhu';
    } else {
        $site_url = 'bjsrawpetfood.test';
    }
}

$cfg = getOCPConfig($site_url);

$usec = 1000000 * ($opt['sleep'] ?? .4);

$url = $cfg['redis']['url'] ?? NULL;
$host = $cfg['redis']['host'] ?? NULL;

if ( ! $url && $host && strpos($host, '/') !== false) {
    die("Unix socket functionality not yet supported.  TODO:  support\n");
}

$obj = getOCPRedisClient($site_url, $user, $pass);
$obj->select($cfg['redis']['database'] ?? 0);
$first = $prev = getCommandCounts($obj);
$totl = $totals = [];

declare(ticks = 10);
pcntl_signal(SIGINT, 'sigintHandler');

$n = 0;

$old_url = $url;

$ip_address = gethostbyname($site_url);

while (!$signaled) {
    $buffer = '';

    $cfg = getOCPConfig($site_url);

    $client = $cfg['redis']['client'];
    $database = $cfg['redis']['database'] ?? 0;
    $url = $cfg['redis']['url'];
    $prefetch = $cfg['redis']['prefetch'] ? 'true' : 'false';
    $split = $cfg['redis']['split_alloptions'] ? 'true' : 'false';
    $cron = $cfg['disable_cron'] ? 'true' : 'false';
    $compression = $cfg['redis']['compression'] ?? 'none';
    $serializer = $cfg['redis']['serializer'] ?? 'none';
    $hash = getOCPHash($site_url, $client, $prefetch, $split, $cron);

    if ($url != $old_url) {
        $obj = getOCPRedisClient($url);
        $obj->select($database);
    }

    list($usage, $peak) = getUsage($obj);
    $os = getRedisOs($obj);
    $opsec = number_format(getOpsPerSec($obj));

    $dbsize = $obj->dbsize();

    $buffer .= date('m/d/Y h:i:s a', time()) . "\n\n";
    $buffer .= "       SITE URL: $site_url ($ip_address)\n";
    $buffer .= "            URL: $url\n";
    $buffer .= "          Redis: $usage ($peak peak), $opsec ops/sec\n";
    $buffer .= "             OS: $os\n";
    $buffer .= "         DBSIZE: $dbsize\n";
    $buffer .= "         WP/OCP: client   $client\n";
    $buffer .= "                 prefetch $prefetch\n";
    $buffer .= "                 split    $split\n";
    $buffer .= "                 nocron   $cron\n";
    $buffer .= "                 cmp/ser  $compression/$serializer\n\n";

    $curr = getCommandCounts($obj);
    $diff = diffCommandCounts($prev, $curr, $neg);
    $totl = diffCommandCounts($first, $curr);

    if ($neg) {
        $first = $prev = getCommandCounts($obj);
        continue;
    }

    $dt = $tt = 0;

    $all_keys = allKeys($curr, $diff, $totl);
    foreach ($all_keys as $cmd) {
        $d = $diff[$cmd] ?? 0;
        $t = $totl[$cmd] ?? 0;

        if ($d + $t) {
            $tmp_diff[$cmd] = $d;
            $tmp_totl[$cmd] = $t;
        }

        $dt += $d;
        $tt += $t;
    }

    $maxlen = strlen("CLIENT|TRACKING");

    arsort($tmp_totl);
    foreach (array_keys($tmp_totl) as $cmd) {
        $buffer .= getLine($cmd, $maxlen, $tmp_diff[$cmd], $tmp_totl[$cmd]);
    }

    $buffer .= getLine('TOTAL', $maxlen, $dt, $tt);

    if ( ! isset($totals[$hash]))
        $totals[$hash] = $totl;
    else if (array_sum($totals[$hash]) < array_sum($totl))
        $totals[$hash] = $totl;

    clearScreen();
    echo $buffer;

    $prev = $curr;
    usleep($usec);

    $old_url = $url;
}

list($hlen, $cmdlen, $nlen) = getLens($totals);

echo "--- Per OCP Config Stats ---\n\n";
foreach ($totals as $hash => $stats) {
    arsort($stats);

    foreach ($stats as $cmd => $n) {
        printTotalLine($hash, $hlen, $cmd, $cmdlen, $n, $nlen);
        $hash = '';
    }
    printTotalLine('', $hlen, 'TOTAL', $cmdlen, array_sum($stats), $nlen);
    echo "\n";
}

exit(0);
