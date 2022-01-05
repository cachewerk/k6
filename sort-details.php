<?php
ini_set('memory_limit', '1G');

require_once('utils.php');

$opt = getopt('', ['sort:', 'uri:', 'dump:', 'csv'], $optind);
$sort = $opt['sort'] ?? 'avg';
$the_uri = $opt['uri'] ?? NULL;
$dump = $opt['dump'] ?? NULL;
$csv = isset($opt['csv']);

$valid_sorts = ['avg', 'min', 'max', 'cnt', 'pct'];

if (!in_array($sort, $valid_sorts))
    panicAbort("Valid sort values are: " . implode(', ', $valid_sorts));

if ($optind >= $argc)
    panicAbort("Pass at least one file");

$kmax = $vmax = 0;
$runs = [];

$files = [];
for ($i = $optind; $i < $argc; $i++) {
    $files[] = $argv[$i];
}

foreach ($files as $file) {
    if ( ! is_file($file))
        panicAbort("Can't open file '$file' for reading");

    $str = file_get_contents($file);
    if ( ! $str)
        panicAbort("Can't read data from '$file'");

    $arr = explode("\n", $str);

    for ($i = 0; $i < count($arr) - 2; $i++) {
        $arr[$i] .= ',';
    }

    $str = '[' . implode("\n", $arr) . ']';

    $arr = json_decode($str, true);
    if ( ! $arr) panicAbort("Can't decode json data");

    $stats = [];

    foreach ($arr as $metric) {
        if (!isset($metric['type']) || $metric['type'] != 'Point' ||
            $metric['metric'] != 'http_req_duration')
            continue;

        $k = $metric['data']['tags']['url'];
        $k = rtrim($k, '/');
        $v = sprintf("%02.2f", $metric['data']['value']);

        if (strlen($k) > $kmax) $kmax = strlen($k);
        if (strlen($v) > $vmax) $vmax = strlen($v);

        $stats[$k][] = $v;
    }

    foreach ($stats as $uri => $values) {
        $avg = sprintf("%02.2f", array_sum($values) / count($values));
        $stats[$uri]['tot'] = sprintf("%02.2f", array_sum($values));
        $stats[$uri]['cnt'] = count($values);
        $stats[$uri]['min'] = min($values);
        $stats[$uri]['max'] = max($values);
        $stats[$uri]['avg'] = $avg;
    }


  /* Sort by timing so we can record percentile per uri */
    uasort($stats, function ($a, $b) {
        if ($a > $b)
            return 1;
        else
            return -1;
    });

    $runs[] = $stats;

    $pos = 0;
    foreach ($stats as $uri => $v) {
        $v = $v['avg'];

        if ( ! isset($uri_pos[$uri])) {
            $uri_pos[$uri]['on'] = 0;
            $uri_pos[$uri]['of'] = 0;
        }

        $uri_pos[$uri]['on'] += $pos;
        $uri_pos[$uri]['of'] += count($stats);

        $pos++;
    }
}

$uris = [];
foreach ($runs as $run) {
    $uris = array_unique(array_merge($uris, array_keys($run)));
}

$stats = [];

foreach ($uris as $uri) {
    $min = $max = $tot = NULL;
    $cnt = 0;

    foreach ($runs as $run) {
        if ( ! isset($run[$uri]))
            continue;

        $v = $run[$uri];
        if ($min === NULL || $v['min'] < $min)
            $min = $v['min'];
        if ($max === NULL || $v['max'] > $max)
            $max = $v['max'];
        $tot += $v['tot'];
        $cnt += $v['cnt'];
    }

    $stats[$uri] = [
        'pct' => sprintf("%02.2f", 100 * ($uri_pos[$uri]['on'] / $uri_pos[$uri]['of'])),
        'min' => $min,
        'max' => $max,
        'avg' => sprintf("%02.2f", $tot / $cnt),
        'cnt' => $cnt,
    ];
}

uasort($stats, function($a, $b) use ($sort) {
    $av = $a[$sort] ?? NULL;
    $bv = $b[$sort] ?? NULL;
    if ($av === NULL || $bv === NULL)
        die("Error:  Can't find '$sort' in stats");
    return $av > $bv ? 1 : -1;
});

if (!$dump) {
    $inner = ['pct', 'cnt', 'min', 'max', 'avg'];
    if ($csv) {
        $hdr = ['uri', 'pct', 'cnt', 'min', 'max', 'avg'];
        fputcsv(STDOUT, $hdr);
        foreach ($stats as $uri => $info) {
            $row = [$uri];
            foreach ($inner as $is) {
                $row[] = $info[$is];
            }
            fputcsv(STDOUT, $row);
        }
    } else {
        echo str_pad('uri', $kmax) . ' ';
        foreach ($inner as $hdr) {
            echo str_pad($hdr, $vmax, ' ', STR_PAD_LEFT) . ' ';
        }
        echo "\n";

        foreach ($stats as $uri => $info) {
            echo str_pad($uri, $kmax) . ' ';
            foreach ($inner as $is) {
                echo str_pad($info[$is], $vmax, ' ', STR_PAD_LEFT) . ' ';
            }
            echo "\n";
        }
    }
} else {
    $uris = array_reverse(array_keys($stats));
    echo json_encode(array_reverse(array_slice($uris, 0, $dump)), JSON_PRETTY_PRINT);
}
