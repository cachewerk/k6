<?php

function panicAbort($msg) {
    fprintf(STDERR, "Error:  $msg\n");
    exit(1);
}

function loadFiles($pat) {
    $files = [];

    foreach (new DirectoryIterator('data/details') as $file) {
        if (!$file->isFile() || $file->isDot() || !fnmatch($pat, $file))
            continue;

        $files[] = "data/details/$file";
    }

    return $files;
}

function verifyUnique($afiles, $bfiles) {
    foreach ($afiles as $afile) {
        if (in_array($afile, $bfiles)) {
            panicAbort("'$afile' is in both file sets, aborting!");
        }
    }
}

function getCmd($files) {
    return "php sort-details.php --csv " . implode(' ', $files);
}

function execCmd($cmd) {
    $result = [];

    $res = shell_exec($cmd);
    if ( ! $res) panicAbort("Empty response when executing command, aborting\n");
    $rows = array_filter(explode("\n", $res));
    assert($rows);

    $hdr = array_shift($rows);
    $hdr = str_getcsv($hdr);
    assert(count($hdr) == 6);

    foreach ($rows as $row) {
        $arr = str_getcsv($row);
        assert(count($arr) == 6);
        $result[$arr[0]] = array_combine($hdr, $arr);
    }

    return $result;
}

function vmax($arr) {
    $result = [
        'a_cnt' => 0, 'a_pct' => 0, 'a_min' => 0, 'a_max' => 0, 'a_avg' => 0,
        'b_cnt' => 0, 'b_pct' => 0, 'b_min' => 0, 'b_max' => 0, 'b_avg' => 0
    ];

    $result['uri'] = 0;
    foreach ($arr as $uri => $data) {
        $len = strlen($uri);
        if ($len > $result['uri'])
            $result['uri'] = $len;

        foreach (['cnt', 'pct', 'min', 'max'] as $stat) {
            foreach (['a', 'b'] as $prefix) {
                $key = "{$prefix}_{$stat}";
                $len = strlen($data[$key]);
                if ($len > $result[$key])
                    $result[$key] = $len;
            }
        }
    }

    return $result;
}

$opt = getopt('a:b:', ['stat:', 'json', 'raw', 'limit:'], $optind);
$apat = $opt['a'] ?? NULL;
$bpat = $opt['b'] ?? NULL;
$stat = $opt['stat'] ?? 'max';
$json = isset($opt['json']);
$raw = isset($opt['raw']);
$limit = $opt['limit'] ?? -1;

if (!in_array($stat, ['cnt', 'pct', 'min', 'max', 'avg']))
    panicAbort("Pass a valid stat to pin off");

if (!$apat || !$bpat || $apat == $bpat)
    panicAbort("Must pass an 'a' and 'b' pattern, and they can't be equal!");

if (strpos($apat, '*') === false) $apat = "*$apat*";
if (strpos($bpat, '*') === false) $bpat = "*$bpat*";

$afiles = loadFiles($apat);
if ( ! $afiles) panicAbort("Our 'a' files are empty, aborting");
$bfiles = loadFiles($bpat);
if ( ! $bfiles) panicAbort("Our 'b' files are empty, aborting");

$acmd = getCmd($afiles);
$adat = execCmd($acmd);
$bcmd = getCmd($bfiles);
$bdat = execCmd($bcmd);

$uris = array_keys(array_intersect_key($adat, $bdat));

$union = [];
foreach ($adat as $uri => $data) {
    $row = ['uri' => $uri];
    foreach (['cnt', 'pct', 'min', 'max', 'avg'] as $metric) {
        $row['uri'] = $uri;
        $row['a_' . $metric] = $data[$metric];
        $row['b_' . $metric] = $bdat[$uri][$metric];
        $row['d_' . $metric] = $data[$metric] - $bdat[$uri][$metric];
    }

    $union[$uri] = $row;
}

uasort($union, function ($a, $b) use ($stat) {
    return $a["d_$stat"] < $b["d_$stat"] ? 1 : -1;
});

$vmax = vmax($union);
$on = 0;

if ($json) {
    $uris = [];
    foreach ($union as $data) {
        $uris[] = $data['uri'];
        if ($limit >= 1 && ++$on >= $limit)
            break;
    }
    echo json_encode($uris, JSON_PRETTY_PRINT);
} else if ($raw) {
    foreach ($union as $k => $data) {
        echo $data['uri'] . "\n";
        if ($limit >= 1 && ++$on >= $limit)
            break;
    }
} else {
    echo str_pad('URI', $vmax['uri']) . ' ';
    foreach (['a', 'b'] as $pfx) {
        echo str_pad("{$pfx}_{$stat}", $vmax["{$pfx}_{$stat}"], ' ', STR_PAD_LEFT) . ' ';
    }
    echo str_pad('DELTA', 8, ' ', STR_PAD_LEFT) . "\n";

    foreach ($union as $k => $data) {
        echo str_pad($data['uri'], $vmax['uri']) . ' ';
        foreach (['a', 'b'] as $pfx) {
            $v = $data["{$pfx}_{$stat}"];
            echo str_pad($v, $vmax["{$pfx}_{$stat}"], ' ' , STR_PAD_LEFT) . ' ';
        }

        $delta = round($data["d_{$stat}"], 2);
        echo str_pad(sprintf("%02.2f", $delta), 8, ' ', STR_PAD_LEFT) . "\n";

        if ($limit >= 1 && ++$on >= $limit)
            break;
    }
}
