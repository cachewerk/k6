<?php
require_once(__DIR__ . '/utils.php');

function getRedisInfo($file) {
    $data = jsonDecodeFileOrDie($file);
    assert(isset($data['redis_info']));
    return $data['redis_info'];
}

function getConfigInfo($file) {
    $data = jsonDecodeFileOrDie($file);
    assert(isset($data['config']));
    return $data['config'];
}

function getRelativeSummaryPath($index) {
    assert(is_dir('data/summary'));

    $index *= -1;

    $paths = [];

    foreach (new DirectoryIterator('data/summary') as $dir) {
        if ($dir->isDot() || $dir->isFile())
            continue;

        $paths[$dir->getMTime()][] = $dir->getFilename();
    }

    ksort($paths);
    $paths = call_user_func_array('array_merge', $paths);

    if ( ! $paths)
        panicAbort("No runs found in data/summary");

    $paths = array_reverse($paths);
    var_dump($paths);die();
    if ($index < count($paths))
        return "data/summary/" . $paths[$index];

    panicAbort("There are only " . count($paths) . " but index $index was requested!");
}

function fixupSummaryPath($path) {
    if (is_dir($path))
        return $path;

    if (is_dir("data/summary/$path")) {
        return "data/summary/$path";
    } else if (is_dir("data/summary/run-$path")) {
        return "data/summary/run-$path";
    } else {
        panicAbort("No idea what to do with path '$path'");
    }

    return $path;
}

function humanReadableTimeDifference(DateInterval $diff) {
    $parts = [];

    if ($diff->y > 0)
        $parts[] = $diff->y . " year" . ($diff->y > 1 ? "s" : "");
    else if ($diff->m > 0)
        $parts[] = $diff->m . " month" . ($diff->m > 1 ? "s" : "");
    else if ($diff->d > 0)
        $parts[] = $diff->d . " day" . ($diff->d > 1 ? "s" : "");
    else if ($diff->h > 0)
        $parts[] = $diff->h . " hour" . ($diff->h > 1 ? "s" : "");
    else if ($diff->i > 0)
        $parts[] = $diff->i . " minute" . ($diff->i > 1 ? "s" : "");
    else if ($diff->s > 0)
        $parts[] = $diff->s . " second" . ($diff->s > 1 ? "s" : "");

    $last_part = array_pop($parts);
    $formatted = $parts ? implode(", ", $parts) . " and " . $last_part : $last_part;
    return $formatted . " ago";
}



function getElapsedTime($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    return humanReadableTimeDifference($now->diff($ago));
//    $diff = $now->diff($ago);
//
//    $diff->w = floor($diff->d / 7);
//    $diff->d -= $diff->w * 7;
//
//    $string = array(
//        'y' => 'year',
//        'm' => 'month',
//        'w' => 'week',
//        'd' => 'day',
//        'h' => 'hour',
//        'i' => 'minute',
//        's' => 'second',
//    );
//    foreach ($string as $k => &$v) {
//        if ($diff->$k) {
//            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
//        } else {
//            unset($string[$k]);
//        }
//    }
//
//    if (!$full) $string = array_slice($string, 0, 1);
//    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function readDataFile($file) {
    $data = json_decode(file_get_contents($file), true);

    if ( ! $data)
        die("Error:  can't read or decode information in '$file'");

    return $data;
}

function getFilters($filter) {
    $result = [];

    if (! $filter)
        return $result;

    $ok_filters = ['client', 'prefetch', 'split_alloptions', 'disable_cron'];

    $filters = explode(',', $filter);
    foreach ($filters as $filter) {
        $bits = explode(':', $filter);
        if (count($bits) != 2)
            panicAbort("Invalid filter string '$filter'");

        list($field, $val) = $bits;
        if ( ! in_array($field, $ok_filters))
            panicAbort("Unknown filter field '$field', valid: (" . implode(', ', $ok_filters) . ")");

        $val = strtolower(trim($val));
        if ($field != 'client') {
            if ($val == 'yes' || $val == 'true' || $val == 1 || $val == 'y')
                $val = true;
            else if ($val == 'no' || $val == 'false' || $val == 0 || $val == 'n')
                $val = false;
            else panicAbort("Unknown filter value '$val'");
        } else if ($val != 'relay' && $val != 'phpredis')
            panicAbort("Valid client filters are 'relay' and 'phpredis'");

        $result[$field] = $val;
    }

    return $result;
}

$opt = getopt('', ['metric:', 'sort:', 'absolute', 'filter:', 'dump-commands', 'by-hash', 'csv', 'list-metrics'], $optind);
$metric = $opt['metric'] ?? 'http_req_duration';
$sort = $opt['sort'] ?? 'avg';
$absolute = isset($opt['absolute']);
$filter = getFilters($opt['filter'] ?? NULL);
$dump_commands = isset($opt['dump-commands']);
$by_hash = isset($opt['by-hash']);
$csv = isset($opt['csv']);
$list_metrics = isset($opt['list-metrics']);
$paths = NULL;

if ($argc == $optind) {
    assert(is_dir('data/summary'));

    $paths = [];
    foreach (new DirectoryIterator('data/summary') as $subdir) {
        if ($subdir->isDot() || $subdir->isDir() == false)
            continue;

        $time = $subdir->getCTime();
        $paths[$time] = $subdir->getFileName();
    }

    if ( ! $paths)
        die("Error:  Can't find a run directory in 'data' path.");

    ksort($paths);
    $paths = ['data/summary/' . array_pop($paths)];
} else {
    for ($i = $optind; $i < $argc; $i++) {
        $paths[] = $argv[$i];
    }

}

$files = [];
assert($paths != NULL);

foreach ($paths as $path) {
    $path = fixupSummaryPath($path);

    $pcount = 0;
    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot())
            continue;
        $pcount++;
    }

    if ($pcount == 0) {
        fprintf(STDERR, "Warning:  Path $path is empty!\n");
        rmdir($path);
        continue;
    }

    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot())
            continue;

        $file = "$path/" . $file->getFileName();
        $files[$file] = readDataFile($file);
    }
}

if ( ! $files)
    panicAbort("No files to process!  Empty path?");

function toSeconds($t) {
    if (is_numeric($t))
        return $t;

    assert(strlen($t) > 0);

    $num = substr($t, 0, strlen($t) - 1);
    assert(is_numeric($num));
    $suf = strtolower(substr($t, strlen($t) - 1));

    if ($suf == 's')
        return $num;
    else if ($suf == 'm')
        return $num * 60;
    else if ($suf == 'h')
        return $num * 60 * 60;

    panicAbort("Don't understand time suffix '$suf'\n");
}

$printed_redis = false;
foreach (array_keys($files) as $file) {
    if (!$printed_redis) {
        $info = getConfigInfo($file);

        $duration = str_replace('s', '', $info['duration'] ?? -1);
        $tag = $info['tag'] ?? 'none';
        $redis_info = getRedisInfo($file);
        fprintf(STDERR, "Tag: $tag, Duration: %s, Redis %s, os: %s, used_memory: %s\n\n",
               gmdate("H:i:s", toSeconds($duration)), $redis_info['version'], $redis_info['os'],
               $redis_info['used_memory_human']);
        $printed_redis = true;
    }

    fprintf(STDERR, "Input file: $file\n");
}

echo "\n";

function listMetrics($data) {
    $metrics = $data['metrics'];
    foreach (array_keys($metrics) as $metric) {
        echo "Metric: $metric (" . implode(',', array_keys($metrics[$metric])) . ")\n";
    }
}

foreach ($files as $file => $metrics) {
    $tmp = $metrics['metrics']['http_req_duration{expected_response:true}'];
    $files[$file]['metrics']['http_req_duration_true'] = $tmp;
    unset($files[$file]['metrics']['http_req_duration{expected_response:true}']);
}

if ($list_metrics) {
    foreach ($files as $file => $metrics) {
        listMetrics($metrics);
        exit(0);
    }
} else {
    $metrics = $files[array_rand($files)]['metrics'];
    if ( ! isset($metrics[$metric])) {
        echo "Don't see metric '$metric'!\n";
        listMetrics($metrics);
        die();
    }

    $inner = array_keys($metrics[$metric]);

    if ( ! in_array($sort, $inner)) {
        if (in_array('avg', $inner))
            $sort = 'avg';
        else if (in_array('http_reqs', $inner))
            $sort = 'http_reqs';
        else if (in_array('rate', $inner))
            $sort = 'rate';
        else {
            die("Can't figure out what to sort by\n");
            print_r($inner);
        }
    }
}

foreach ($files as $file => $metrics) {
    $match = true;
    foreach ($filter as $field => $val) {
        if ($metrics['config'][$field] != $val) {
            $match = false;
            break;
        }
    }

    if ( ! $match)
        continue;

    $entry = $metrics['metrics'][$metric];

    $imetrics = [];
    foreach ($inner as $imetric) {
        $v = $entry[$imetric] ?? NULL;
        if ($v === NULL)
            die("Error:  Can't find '$imetric' in stats entry!");

        if (is_float($v)) $v = sprintf("%02.2f", $v);
        $imetrics[$imetric] = $v;
    }

    $imetrics['client'] = $metrics['config']['client'];
    $imetrics['cmds'] = array_sum($metrics['metrics']['redis_commands']);
    $imetrics['io'] = $metrics['metrics']['redis_network_io'];
    $imetrics['usage'] = $metrics['metrics']['redis_used_memory'] ?? 0;
    $imetrics['sha'] = $metrics['relay_sha'] ?? 'unknown';
    $imetrics['when'] = getElapsedTime('@' . filectime($file));
    $imetrics['timestamp'] = filectime($file);

    $cfg = $metrics['config'];
    $cfg['file'] = $file;
    $key = serialize($cfg);

    $imetrics['key'] = $key;
    $stats[$key] = $imetrics;

}
$inner[] = 'cmds';
$inner[] = 'usage';
$inner[] = 'io';

if (preg_match('/p([0-9]+)/i', $sort, $matches) && count($matches) == 2) {
    $sort = "p(" . $matches[1] . ")";
}

function statsHash($s) {
    $s = unserialize($s);
    return implode('-', [
        $s['prefetch'] ? 'prefetch' : 'noprefetch',
        $s['split_alloptions'] ? 'split' : 'nosplit',
        $s['disable_cron'] ? 'nocron' : 'cron',
        $s['compression']
    ]);
}

uasort($stats, function($a, $b) use ($sort, $by_hash) {
    if ($by_hash) {
        $a_hash = statsHash($a['key']);
        $b_hash = statsHash($b['key']);
        $cmp = strcmp($a_hash, $b_hash);
        if ($cmp != 0)
            return $cmp;
    }

    $av = $a[$sort] ?? NULL;
    $bv = $b[$sort] ?? NULL;
    if ($av === NULL || $bv === NULL) {
        var_dump($a);
        var_dump($b);
        die("Error:  Can't find '$sort' in stats");
    }
    return $av > $bv ? 1 : -1;
});

$kmax = 0;
$vmax = [];
$rows = [];
$last_hash = NULL;

$inner = array_merge(['when', 'client', 'prefetch', 'split', 'cmp', 'disable_cron'], $inner);

foreach ($stats as $ser_key => $values) {
    $config = unserialize($ser_key);

    $hash = statsHash($values['key']);

    $row = $values;
    if ($config['client'] == 'relay') {
        $relay_sha = substr($config['relay_sha'], 0, 7);
        $row['client'] = $config['client'] . '-' . $relay_sha;
    } else {
        $row['client'] = $config['client'];
    }

    if ($by_hash && $last_hash == $hash) {
        $row['cmp'] = '-';
        $row['prefetch'] = '-';
        $row['split'] = '-';
        $row['disable_cron'] = '-';
        $row['spacer'] = true;
    } else {
        $row['cmp'] = $config['compression'];
        $row['prefetch'] = $config['prefetch'] ? 'yes' : 'no';
        $row['split'] = $config['split_alloptions'] ? 'yes' : 'no';
        $row['disable_cron'] = $config['disable_cron'] ? 'yes' : 'no';
    }

    foreach ($inner as $key) {
        $l = max(strlen($row[$key]), strlen($key));
        if ( ! isset($vmax[$key]) || $l > $vmax[$key])
            $vmax[$key] = $l;
    }

    $last_hash = $hash;

    $rows[$ser_key] = $row;
}

if ($csv) {
    fputcsv(STDOUT, $inner);
} else {
    foreach ($inner as $metric) {
        echo str_pad($metric, $vmax[$metric], ' ', STR_PAD_LEFT) . ' ';
    }
    echo "\n";
}

foreach ($rows as $row) {
    if ($csv) {
        $csv_row = [];
        foreach ($inner as $metric) {
            $csv_row[] = $row[$metric];
        }
        fputcsv(STDOUT, $csv_row);
    } else {
        foreach ($inner as $metric) {
            echo str_pad($row[$metric], $vmax[$metric], ' ', STR_PAD_LEFT) . ' ';
        }
        echo "\n";
        if (isset($row['spacer'])) echo "\n";
    }
}

function getConfigHash($cfg) {
    return implode('-', [
        $cfg['client'],
        $cfg['prefetch'] ? 'prefetch' : 'noprefetch',
        $cfg['split_alloptions'] ? 'split' : 'nosplit',
        $cfg['disable_cron'] ? 'nocron' : 'cron',
        $cfg['compression']
    ]);
}

if ($dump_commands) {
    echo "\n";

    $maxlen = 0;

    foreach ($files as $file => $metrics) {
        $hash = getConfigHash($metrics['config']);
        $cmds = $metrics['metrics']['redis_commands'];

        if (strlen($hash) > $maxlen)
            $maxlen = strlen($hash);

        arsort($cmds);
        $by_cmds[$hash] = $cmds;
    }

    uasort($by_cmds, function ($a, $b) {
        $t1 = array_sum($a);
        $t2 = array_sum($b);
        return $t1 > $t2 ? 1 : -1;
    });

    foreach ($by_cmds as $hash => $arr) {
        $printed = false;
        foreach ($arr as $cmd => $n) {
            if ($cmd == 'client|tracking') $cmd = 'client';

            if ( ! $printed) {
                echo str_pad($hash, $maxlen) . ' ';
            } else {
                echo str_repeat(' ', $maxlen + 1);
            }
            $printed = true;

            echo str_pad($cmd, 10, ' ', STR_PAD_LEFT) . ' ' .
                 str_pad(number_format($n), 10, ' ', STR_PAD_LEFT) . "\n";
        }

        echo str_repeat(' ', $maxlen + 1);
        echo str_pad('TOTAL', 10, ' ', STR_PAD_LEFT) . ' ' .
             str_pad(number_format(array_sum($arr)), 10, ' ', STR_PAD_LEFT) . "\n\n";
    }
}

