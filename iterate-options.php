<?php
require_once('utils.php');

function getRunPath($id) {
    if (is_dir("data/summary/$id"))
        return "data/summary/$id";
    else if (is_dir("data/summary/run-$id"))
        return "data/summary/run-$id";
    else if (is_dir($id)) {
        return $id;
    }

    panicAbort("Wasn't able to deduce which run directory belonged to '$id'");
}

function getNextPath($dry) {
    if ( ! is_dir('data/summary') && !mkdir('data/summary', 0777, true))
        panicAbort("Error:  Can't create data directory");

    for ($i = 1; ; $i++) {
        $path = "data/summary/run-$i";
        if ( ! is_dir("$path")) {
            if ( ! $dry && ! mkdir($path))
                panicAbort("Can't create data path '$path'");
            return $path;
        }
    }
}

function getHeader($on, $of, $plain) {
    if ($plain) {
        return sprintf("[%02d/%02d]: ", $on, $of);
    } else {
        return sprintf("[%02d/%02d] %s:", $on, $of, getDateTime());
    }
}

function opt_transform($v, $check) {
    $v = strtolower($v);
    foreach (array_keys($check) as $k) {
        if (in_array($v, $check[$k]))
            return $k;

    }

    panicAbort("Invalid option '$v'");
}

function to_false_true($v) {
    return opt_transform($v,
        [
            'false' => [0, '0', 'f', 'false', 'n', 'no'],
            'true'  => [1, '1', 't', 'true', 'y', 'yes']
        ]);
}

function to_client($v) {
    return opt_transform($v,
        [
            'relay' => ['relay'],
            'phpredis' => ['phpredis']
        ]);
}

function getCSLOption($opt, $keys, callable $fn, $default) {
    foreach (is_array($keys) ? $keys : [$keys] as $key) {
        if (!isset($opt[$keys]))
            continue;

        $res = [];
        foreach (explode(',', $opt[$key]) as $v) {
            $res[] = $fn($v);
        }

        return $res;
    }

    return $default;
}

function printLine($header, $c1, $c2) {
    $hpad = str_pad(' ', strlen($header));

    echo $header . ' ' . $c1[0] . "\n" . $hpad . " \t" . $c1[1] . "\n";
    echo $hpad   . ' ' . $c2[0] . "\n" . $hpad . " \t" . $c2[1] . "\n";
}

$def_clients = ['phpredis', 'relay'];
$def_prefetch = ['false', 'true'];
$def_split = ['false', 'true'];
$def_cron = ['false', 'true'];

$opt = getopt('',
    ['duration:', 'path:', 'dry-run', 'compression:', 'clients:', 'prefetch:',
     'split:', 'cron:', 'site-url:', 'user:', 'pass:', 'cycle-fpm', 'cycle-fpm-all',
     'disable-newrelic', 'randomize', 'run-id:', 'zstd-dictionary:', 'vus:'
]);

$dry = isset($opt['dry-run']);
$user = $opt['user'] ?? NULL;
$pass = $opt['pass'] ?? NULL;

$duration = $opt['duration'] ?? '10s';
$compression = array_filter(explode(',', $opt['compression'] ?? 'zstd'));
$set_cron = getCSLOption($opt, 'cron', 'to_false_true', $def_cron);
$set_clients = getCSLOption($opt, 'clients', 'to_client', $def_clients);
$set_prefetch = getCSLOption($opt, 'prefetch', 'to_false_true', $def_prefetch);
$set_split = getCSLOption($opt, 'split', 'to_false_true', $def_split);
$site_url = getSiteUrlOpt($opt); //$opt['site-url']?? NULL;
$cycle_fpm = isset($opt['cycle-fpm']);
$cycle_fpm_all = isset($opt['cycle-fpm-all']);
$vus = $opt['vus'] ?? 20;
if ($cycle_fpm_all) $cycle_fpm = false;
$isolate_client = isset($opt['isolate-client']);
$disable_newrelic = isset($opt['disable-newrelic']);
$randomize = isset($opt['randomize']);
$zstd_dictionary = $opt['zstd-dictionary'] ?? '';

$run_id = $opt['run-id'] ?? NULL;

if ($run_id)
    $path = getRunPath($run_id);
else
    $path = $opt['path'] ?? getNextPath($dry);

if ( ! $site_url) {
    panicAbort("Please pass a site-url!");
}

$relay_bin_sha = $dry ? 'unknown' : getRelayBinaryId($site_url);
//$relay_git_sha = $dry ? 'unknown' : getRelayGitSha($site_url);

if ($path && ! ($dry || is_dir($path)))
    panicAbort("'$path' is not a valid path");

if ( ! is_array($compression) || ! $compression)
    panicAbort("No compression array specified");

$id = uniqid();

$on = 1;
$of = count($set_clients) * count($set_prefetch) * count($set_split) * count($set_cron) * count($compression);
$hlen = strlen(getHeader($of, $of, $dry));

foreach ($set_clients as $client) {
    if ($cycle_fpm) {
        cthCycleFPM($site_url, $client, $disable_newrelic, $isolate_client ? $client : NULL, $zstd_dictionary);
    }

    foreach ($set_prefetch as $prefetch) {
        foreach ($set_split as $split) {
            foreach ($set_cron as $cron) {
                foreach ($compression as $cmp) {
                    if ( ! $dry) {
                        flushOCPRedis($site_url, $user, $pass);
                        $cmd1 = getRedisCommandStats($site_url, 'calls', $user, $pass);
                        $cmd1_usec = getRedisCommandStats($site_url, 'usec', $user, $pass);
                        $io1 = getIOStats($site_url);
                        $work1 = getWorkStats($site_url);
                    }

                    $exp_hash = implode('-', [
                        $client,
                        $prefetch == 'true' ? 'prefetch' : 'noprefetch',
                        $split == 'true' ? 'split_alloptions' : 'nosplit_alloptions',
                        $cmp,
                        $cron == 'true' ? 'nocron' : 'cron'
                    ]);

                    if ($dry) {
                        $ocmd = updateOCPSettingsCmd($site_url, $client, $prefetch, $split, $cmp, $cron);
                        $act_hash = 'dry-run';
                    } else {
                        $ocmd = updateOCPSettings($site_url, $client, $prefetch, $split, $cmp, $cron);

                        $act_hash = getOCPHash($site_url);

                        if ($exp_hash != $act_hash)
                            panicAbort("Hash mismatch:  Expected: $exp_hash, Actual: $act_hash");
                    }

                    if ($cycle_fpm_all) {
                        cthCycleFPM($site_url, $exp_hash, $disable_newrelic, $isolate_client, $zstd_dictionary);
                    }

                    $outfile = "$path/$act_hash.$id.json";
                    $cmd = getK6Cmd($site_url, $vus, $duration, NULL, NULL, $outfile);

                    $spos = strpos($cmd, '--summary-export');
                    assert($spos !== false);
                    $dcmd1 = substr($cmd, 0, $spos - 1);
                    $dcmd2 = substr($cmd, $spos);

                    $spos = strpos($ocmd, "--split_alloptions");
                    assert($spos !== false);
                    $ocmd1 = substr($ocmd, 0, $spos - 1);
                    $ocmd2 = substr($ocmd, $spos);

                    printLine(getHeader($on, $of, $dry), [$ocmd1, $ocmd2], [$dcmd1, $dcmd2]);

                    if (!$dry) {
                        shell_exec($cmd);

                        $cmd2 = getRedisCommandStats($site_url, 'calls', $user, $pass);
                        $cmd2_usec = getRedisCommandStats($site_url, 'usec', $user, $pass);
                        $io2 = getIOStats($site_url);
                        $work2 = getWorkStats($site_url);

                        $diff = diffArrays($cmd1, $cmd2);
                        $io_diff = diffArrays($io1, $io2, false);
                        $work_diff = diffArrays($work1, $work2, false);
                        $usec_diff = diffArrays($cmd1_usec, $cmd2_usec, false);

                        list($relay_used, $relay_maxmem) = getRelayMemory($site_url);
                        insertRedisMetrics($outfile, $diff, $io_diff, $work_diff, $relay_maxmem,
                                           getRedisUsage($site_url, $user, $pass));
                        insertRedisInfo($outfile, getOCPRedisInfo($site_url, $user, $pass));
                        insertConfigInfo($outfile, $relay_bin_sha, $client, $prefetch, $split, $cmp, $cron, $duration);

                        $relay_usec = sprintf("Relay: CMD: %sms, RINIT: %sms, RSHUTDOWN: %sms, SIGIO: %sms\n",
                                              number_format($work_diff['command_usec'] / 1000.00, 2),
                                              number_format($work_diff['rinit_usec'] / 1000.00, 2),
                                              number_format($work_diff['rshutdown_usec'] / 1000.00, 2),
                                              number_format($work_diff['sigio_usec'] / 1000.00, 2));

                        $relay_memory = sprintf("%s/%s", number_format($relay_used), number_format($relay_maxmem));

                        echo str_repeat(' ', strlen(getHeader($on, $of, $dry))) . ' ';
                        echo "CMDS: " . number_format(array_sum($diff)) . " Relay MEM: $relay_memory, " .
                             "IO: " . number_format(array_sum($io_diff)) . ', ' .
                             "Redis (millis): " . number_format(array_sum($usec_diff) / 1000.00, 2) . "\n";
                        echo str_repeat(' ', strlen(getHeader($on, $of, $dry))) . ' ' . $relay_usec . "\n";
                    }

                    $on++;
                }
            }
        }
    }
}
