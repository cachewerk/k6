<?php
require_once('utils.php');

function getUpdateHost($site_url) {
    $host = gethostbyname($site_url);
    $linux = strpos(strtolower(php_uname('o')), 'linux') !== false;

    if (substr($host, 0, 3) == '127') {
        return $linux ? 'cthulhu' : 'mini';
    } else if ($host == '192.168.0.148') {
        return 'cthulhu';
    } else if ($host == '192.168.0.174') {
        return 'mini';
    }

    panicAbort("Don't know what to do with site url '$site_url' (host: $host)");
}

function getLocalHost() {
    return strpos(php_uname('o'), 'Linux') !== false ? 'cthulhu' : 'mini';
}

$valid = [
    'url' => ['cthulhu', 'mini'],
    'client' => ['relay', 'phpredis'],
    'prefetch' => false,
    'split_alloptions' => false,
    'compression' => ['zstd', 'lzf', 'lz4'],
];

$opt = getopt('', ['site-url:', 'client:', 'prefetch:', 'split_alloptions:', 'compression:', 'disable_cron:', 'url:']);
$disable_cron = $opt['disable_cron'] ?? NULL;

$site_url = getSiteUrlOpt($opt);


if (!$site_url) panicAbort("Must pass a site url!");

$set = [];

foreach ($valid as $option => $type) {
    $o = $opt[$option] ?? NULL;

    if ($o !== NULL) {
        if (is_array($type)) {
            if ( ! in_array($o, $type)) {
                panicAbort("'$o' is not a valid option for '$option' (valid: " . implode(', ', $type) . ")");
            }
        } else if ($o != 'false' && $o != 'true') {
            $o = $o ? 'true' : 'false';
        }

        $set[$option] = $o;
    }
}

$host = getUpdateHost($site_url);
$local = getLocalHost();

if ($host == 'cthulhu') {
    $ssh_host = "cthulhu";
    $scr_path = "/home/mike/bin/";
} else if ($host == 'mini') {
    $ssh_host = "mini";
    $scr_path = "/Users/michaelgrunder/bin";
} else {
    panicAbort("Impossible code reached");
}

if ($host == $local) {
    $cmd = "$scr_path/update-wp-config";
} else {
    $cmd = "ssh $ssh_host '$scr_path/update-wp-config'";
}

foreach ($set as $k => $v) {
    $cmd .= " --$k $v";
}
if ($disable_cron !== NULL) {
    if ($disable_cron != 'true' && $disable_cron != 'false')
        $disable_cron = $disable_cron ? 'true' : 'false';
    $cmd .= " --disable_cron $disable_cron";

}

$res = shell_exec($cmd);
$arr = json_decode($res, true);

if ($disable_cron !== NULL) {
    $actual = $arr['disable_cron'] ? 'true' : 'false';
    $expected = $disable_cron ? 'true' : 'false';

    if ($actual !== $disable_cron)
        panicAbort("Expected 'DISABLE_WP_CRON' to be '$expected' but it is '$actual'");
}

foreach ($set as $option => $value) {
    if ( ! isset($arr['redis'][$option]))
        panicAbort("Don't see option '$option' in result json!");

    $cmp = $arr['redis'][$option];
    if ($value == 'true' || $value == 'false') {
        $value = $value == 'true' ? true : false;
    }

    if ($option == 'url') {
        $value = strtolower(trim($value));
        if ($value == 'cthulhu') {
            $value = 'redis://default@192.168.0.148:6379';
        } else if ($value == 'mini') {
            $value = 'redis://default@192.168.0.174:6379';
        } else if ($value == 'unixsock') {
            panicAbort("Unimplemented");
        }
    }

    if ($cmp != $value) {
        panicAbort("Expected '$option' to be $value but see $cmp");
    }
}

echo $res;
