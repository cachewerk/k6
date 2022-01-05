#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

$sortBy     = null;
$showAll    = false;
$showOrder  = false;
$showDate   = false;
$aggregate  = false;

$opt = getopt('s:a:ogd', ['since:', 'sort:', 'all', 'order', 'date', 'help', 'aggregate'], $optind);
$sortBy     = $opt['sort'] ?? null;
$showAll    = isset($opt['all']) || isset($opt['a']);
$showOrder  = isset($opt['order']) || isset($opt['o']);
$showDate   = isset($opt['date']) || isset($opt['d']);
$aggregate  = isset($opt['aggregate']) || isset($opt['g']);
$since      = $opt['since'] ?? null;

if (isset($opt['help'])) {
    fwrite(STDERR, "Usage: php flatten.php <dir> [--sort=\"Column\"] [--all] [--order|-o] [--date|-d] [--aggregate|-g]\n");
    exit(0);
}

$dir = $argv[$optind] ?? null;
if (! $dir || ! is_dir($dir)) {
    fwrite(STDERR, "Not a directory: $dir\n");
    exit(1);
}

if ($since !== null) {
    $since = timeToTimestamp($since);
    if ($since === null) {
        fprintf(STDERR, "Warning: Couldn't figure out how to parse date/time '%s'\n", $since);
        exit(1);
    }
}

$entries = array_filter(scandir($dir), fn($e) => $e !== '.' && $e !== '..');
$fullPaths = array_map(fn($e) => "$dir/$e", $entries);
if ($entries && count($entries) > 0 &&
    array_reduce($fullPaths, fn($carry, $f) => $carry && is_dir($f), true))
{
    usort($fullPaths, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $dir = $fullPaths[0] ?? $dir;
    printf("Picking newest subdirectory: %s\n", $dir);
}

if (preg_match('#runs/.*/(\d+)$#', $dir, $matches)) {
    $ts  = $matches[1];
    $ago = time() - (int)$ts;
    printf("Run started %s ago\n", gmdate('H:i:s', $ago));
}

// Collect and sort files by mtime descending
$files = [];
foreach (scandir($dir) as $file) {
    $fp = "$dir/$file";
    if (is_file($fp)) {
        $filetime = filemtime($fp);
        if ($since !== null && $filetime < $since) {
            continue; // Skip files older than --since
        }

        $files[] = ['path' => $fp, 'name' => $file, 'mtime' => $filetime];
    }
}

// If we're aggregating, --order and --date are meaningless
if ($aggregate && ($showOrder || $showDate)) {
    fprintf(STDERR, "--order and --date ignored in aggregate mode\n");
    $showOrder = false;
    $showDate = false;
}

// Assign order based on age (oldest = 1)
if ($showOrder || $showDate) {
    usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
    foreach ($files as $i => &$file) {
        $file['order'] = $i + 1;
        $file['date'] = date('Y-m-d H:i:s', $file['mtime']);
    }
}

$grouped = [];
$rows    = [];

$no_invalidations = false;

foreach ($files as $i => $fileData) {
    $fp = $fileData['path'];
    $json = json_decode(file_get_contents($fp), true);
    if (!is_array($json)) continue;

    $m = $json['metrics'] ?? [];
    $r = $json['setup_data']['relayInfo'] ?? [];

    $client = $r['ocp_client'] ?? null;
    if ($client === null) {
        $relay_v = $json['metrics']['x_using_relay']['value'] ?? null;
        if ($relay_v === null) {
            die("No idea what client\n");
        }
        $client = $relay_v > 0 ? 'relay' : 'redis';
    }

    if ($client === 'phpredis') {
        $client = 'redis';
    }

    $isRelay = ($client === 'relay');
    $cacheMode = $isRelay ? (($r['adaptive_width'] ?? 0) > 0 ? 'A' : 'U') : '';

    if ( ! isset($r['relay_invalidations'])) {
        $no_invalidations = true;
        $invalidations = false;
    } else {
        $invalidations = match($isRelay) {
            true  => $r['relay_invalidations'] == 'enabled' ? 'Y' : 'N',
            false => ''
        };
    }

    $row = [
        'Client'      => $client,
        'FPM'         => $r['fpm_workers'] ?? null,
        'VUs'         => $m['vus_max']['value'] ?? null,
        'I'           => $invalidations,
        'DBs'         => $isRelay ? ($r['max_endpoint_dbs'] ?? null) : '',
        'events'      => $isRelay ? ($r['adaptive_min_events'] ?? null) : '',
        'ratio'       => $isRelay ? ($r['adaptive_min_ratio'] ?? null) : '',
        'Mode'        => $cacheMode,
        'Samples'     => 1,
        'Iter'        => $m['iterations']['count'] ?? null,
        'Avg (ms)'    => $m['http_req_duration']['avg'] ?? null,
        'p(90)'       => $m['http_req_duration']['p(90)'] ?? null,
        'p(95)'       => $m['http_req_duration']['p(95)'] ?? null,
        'Max (ms)'    => $m['http_req_duration']['max'] ?? null,
        'Cmds'        => extractScalar($m['redis_commands_total'] ?? []),
        'Cached Keys' => $isRelay ? ($m['cached_keys']['value'] ?? null) : '',
        'Total Keys'  => $m['redis_keys']['value'] ?? null,
        'Cached (B)'  => $m['cached_key_size']['value'] ?? null,
        'Total (B)'   => $m['redis_key_size']['value'] ?? null,
    ];

    if ($no_invalidations) unset($row['I']);

    if ($showOrder) $row['Order'] = $fileData['order'] ?? null;
    if ($showDate)  $row['Date']  = $fileData['date'] ?? null;

    if ($aggregate) {
        $groupKey = json_encode([
            $row['Client'],
            $row['FPM'],
            $row['VUs'],
            $row['DBs'],
            $row['events'],
            $row['ratio'],
            $row['Mode'],
            $row['I'],
        ]);

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = $row;
            $grouped[$groupKey]['__sums'] = [];
        }

        $group = &$grouped[$groupKey];
        $group['Samples']++;

        foreach (['Avg (ms)', 'p(90)', 'p(95)', 'Max (ms)', 'Cmds', 'Cached Keys',
                  'Total Keys', 'Cached (B)', 'Total (B)'] as $metric) {
            if (is_numeric($row[$metric])) {
                $group['__sums'][$metric] = ($group['__sums'][$metric] ?? 0.0)
                                          + (float)$row[$metric];
            }
        }
    } else {
        $rows[] = $row;
    }
}

if ($aggregate) {
    foreach ($grouped as $row) {
        $samples = $row['Samples'];
        foreach ($row['__sums'] as $k => $total) {
            $row[$k] = $samples > 0 ? $total / $samples : null;
        }
        unset($row['__sums']);
        $rows[] = $row;
    }
}
// Remove columns that are entirely empty
$nonEmptyCols = [];
foreach ($rows as $row) {
    foreach ($row as $col => $val) {
        if ($val !== null && $val !== '') {
            $nonEmptyCols[$col] = true;
        }
    }
}
$allColumns = isset($rows[0]) ? array_keys($rows[0]) : [];

$alwaysEmpty = array_diff($allColumns, array_keys($nonEmptyCols));

// Strip them from each row
if ($alwaysEmpty) {
    foreach ($rows as &$row) {
        foreach ($alwaysEmpty as $col) {
            unset($row[$col]);
        }
    }
    unset($row); // break ref
}

// Recompute allColumns and columns
$allColumns = isset($rows[0]) ? array_keys($rows[0]) : [];





$uniFpm  = count(array_unique(array_column($rows, 'FPM'), SORT_REGULAR)) === 1;
$uniVus  = count(array_unique(array_column($rows, 'VUs'), SORT_REGULAR)) === 1;
$uniCli  = count(array_unique(array_column($rows, 'Client'), SORT_REGULAR)) === 1;
$uniSmp  = count(array_unique(array_column($rows, 'Samples'), SORT_REGULAR)) === 1;

$hiddenCols = ['Cached (B)', 'Total (B)'];
if ($uniFpm) $hiddenCols[] = 'FPM';
if ($uniVus) $hiddenCols[] = 'VUs';
if ($uniCli) $hiddenCols[] = 'Client';
if ($uniSmp) $hiddenCols[] = 'Samples';

if (!$showAll && ($uniFpm || $uniVus || $uniCli || $uniSmp)) {
    $uniMsgs = [];
    if ($uniCli) $uniMsgs[] = "Client: {$rows[0]['Client']}";
    if ($uniFpm) $uniMsgs[] = "FPM workers: {$rows[0]['FPM']}";
    if ($uniVus) $uniMsgs[] = "VUs: {$rows[0]['VUs']}";
    if ($uniSmp) $uniMsgs[] = "Samples: {$rows[0]['Samples']}";
    printf("%s\n", implode(', ', $uniMsgs));
}

$columns = $showAll ? $allColumns : array_diff($allColumns, $hiddenCols);

// Determine sort column
if (!$sortBy || !in_array($sortBy, $columns, true)) {
    $sortBy = 'Avg (ms)';
}

// Sort numerically, nulls and dashes last
usort($rows, function ($a, $b) use ($sortBy) {
    $x = is_numeric($a[$sortBy]) ? (float)$a[$sortBy] : INF;
    $y = is_numeric($b[$sortBy]) ? (float)$b[$sortBy] : INF;
    return $x <=> $y;
});

$output = new ConsoleOutput();
$style  = (new TableStyle())
    ->setHorizontalBorderChars('')
    ->setVerticalBorderChars(' ')
    ->setDefaultCrossingChar(' ')
    ->setCellRowContentFormat('%s');

$table = new Table($output);
$table->setStyle($style);
$table->setHeaders($columns);

$cellStyle = new TableCellStyle(['align' => 'right']);

foreach ($rows as $row) {
    $cells = [];
    foreach ($columns as $col) {
        $val = $row[$col] ?? null;
        if (is_numeric($val)) {
            $cells[] = new TableCell(formatNumber($val), ['style' => $cellStyle]);
        } else {
            $cells[] = (string)($val ?? '');
        }
    }
    $table->addRow($cells);
}

$table->render();

function extractScalar(array $m): ?float {
    foreach (['avg', 'value', 'med', 'min', 'max', 'p(90)', 'p(95)'] as $k) {
        if (isset($m[$k]) && is_numeric($m[$k])) {
            return (float)$m[$k];
        }
    }
    return null;
}

function timeToTimestamp(string $input): ?int {
    $input = trim($input);

    if (ctype_digit($input)) {
        return (int) $input;
    }

    // Let strtotime handle natural language and common formats
    $ts = strtotime($input);
    return $ts !== false ? $ts : null;
}


function formatNumber($v): string {
    if (!is_numeric($v)) return '';

    $f = (float)$v;
    if ($f > 0 && $f < 1.0) {
        return round($f, 2);
    }

    return floor($f) == $f
        ? number_format((int)$f, 0, '.', ',')
        : number_format($f, 0, '.', ',');
}
