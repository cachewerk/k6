<?php
require_once('utils.php');

function findRunFile($path, $prefix) {
    for ($i = 0; ; $i++) {
        $filename = "{$path}/{$prefix}.run-{$i}.json";
        if ( ! is_file($filename))
            return $filename;
    }
}

$opt = getopt('', ['file:', 'details', 'client:', 'prefetch:', 'split_alloptions:',
                   'compression:', 'disable_cron:', 'duration:', 'dry-run', 'site-url:',
                   'user:', 'pass:']);

foreach (['prefetch', 'split_alloptions', 'disable_cron', 'compression'] as $iopt) {
    $$iopt = $opt[$iopt] ?? NULL;
    if ($$iopt !== NULL) {
        if ($$iopt != 'false' && $$iopt != 'true')
            $$iopt = $$iopt ? 'true' : 'false';
    }
}

$user = $opt['user'] ?? NULL;
$pass = $opt['pass'] ?? NULL;

$site_url = getSiteUrlOpt($opt);
if ( ! $site_url) panicAbort("Please pass a site url");

$client = $opt['client'] ?? NULL;
if ($client && ($client != 'relay' && $client != 'phpredis')) {
    panicAbort("Valid clients are 'phpredis', and 'relay'");
}

$duration = $opt['duration'] ?? '1m';
$file = $opt['file'] ?? NULL;
$dry_run = isset($opt['dry-run']);

if ($file && ! is_file($file))
    die("Error:  '$file' is not a file\n");

$cfg = getOCPConfig($site_url);

updateOCPSettings($site_url, $client, $prefetch, $split_alloptions, $compression, $disable_cron);
validateOCPSettings($site_url, $client, $prefetch, $split_alloptions, $compression, $disable_cron);

$hash = getOCPHash($site_url);

if (isset($opt['details'])) {
    $details = findRunFile('data/details', $hash);
} else {
    $summary = "$hash.json";
}

$cfg = getOCPConfig($site_url);

printf("Client: %s, prefetch: %s, split_alloptions: %s, compression: %s, diable_cron: %s\n",
       $cfg['redis']['client'], $cfg['redis']['prefetch'] ? 'yes' : 'no',
       $cfg['redis']['split_alloptions'] ? 'yes' : 'no',
       $cfg['redis']['compression'],
       $cfg['disable_cron'] ? 'yes' : 'no');

flushOCPRedis($site_url, $user, $pass);


$cmd = getK6Cmd($site_url, $duration, $file, $details ?? NULL, $summary ?? NULL);
printf("[%s]: %s\n", getDateTime(), $cmd);
if ($dry_run)
    exit(0);

if (isset($summary)) {
    $c1 = getRedisCommandStats($site_url, $user, $pass);
    $i1 = getIOStats($site_url);
}
shell_exec($cmd);
if (isset($summary)) {
    $c2 = getRedisCommandStats($site_url, $user, $pass);
    $i2 = getIOStats($site_url);
}

$used_memory = getRedisUsage($site_url, $user, $pass);
var_dump($i1, $i2);
die();
assert(isset($details) || isset($summary));
if (isset($summary)) {
    insertRedisMetrics($summary, diffArrays($c1, $c2), diffArrays($i1, $i2), getRelayBinaryId($site_url), $used_memory);
    insertRedisInfo($summary, getOCPRedisInfo($site_url, $user, $pass));
}
