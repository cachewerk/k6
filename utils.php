<?php

function getSiteUrlOpt($opt) {
    $url = $opt['site-url'] ?? NULL;
    if ($url) {
        file_put_contents('.last-site-url', $url);
        return $url;
    } else if (($url = file_get_contents('.last-site-url'))) {
        return $url;
    }

    return NULL;
}

function generateCallTrace() {
    $e = new Exception();
    $trace = explode("\n", $e->getTraceAsString());
    // reverse array to make steps line up chronologically
    $trace = array_reverse($trace);
    array_shift($trace); // remove {main}
    array_pop($trace); // remove call to this method
    $length = count($trace);
    $result = array();

    for ($i = 0; $i < $length; $i++) {
        $result[] = ($i + 1)  . ')' . substr($trace[$i], strpos($trace[$i], ' '));
    }

    return "\t" . implode("\n\t", $result);
}

function getSiteHost($site_url) {
    static $lookup = [];

    if (isset($lookup[$site_url]))
        return $lookup[$site_url];

    $host = gethostbyname($site_url);
    if ( ! $host)
        panicAbort("Couldn't get IP address for '$site_url'");

    $lookup[$site_url] = $host;
    return $lookup[$site_url];
}

function getOCPMachine($site_url) {
    $host = getSiteHost($site_url);

    if ($host == '192.168.0.148') {
        return "cthulhu";
    } else if ($host == '192.168.0.174') {
        return "mini";
    } else {
        panicAbort("Don't know host '$host'");
    }
}

function getMachine() {
    $uname = strtolower(php_uname('a'));
    if (strpos($uname, 'darwin') !== false) {
        return "mini";
    } else {
        return "ubuntu";
    }
}

function getOSType() {
    $uname = strtolower(php_uname('a'));
    if (strpos($uname, 'darwin') !== false) {
        return "Darwin";
    } else {
        return "Linux";
    }
}

function panicAbort($fmt, ...$args) {
    fprintf(STDERR, "Error: $fmt\n", ...$args);
    echo generateCallTrace();
    echo "\n";
    exit(1);
}

function getOCPRedisHostPort($site_url) {
    $localhost = getSiteHost($site_url);

    $host = $port = NULL;

    if ($host === NULL && $port === NULL) {
        $cmd = "php update-ocp-settings.php --site-url $site_url --json";
        $res = shell_exec($cmd);
        if ( ! $res) panicAbort("Failed to execute command '$cmd'");

        $data = json_decode($res, true);
        if ( ! ($is_url = isset($data['redis']['url'])) &&
             !isset($data['redis']['host']))
        {
            print_r($data);
            panicAbort("Missing ['redis']['url'] and ['redis']['host'] section of ocp settings");
        }

        if ($is_url) {
            $url = $data['redis']['url'];

            $regx = '/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+):([0-9]+)/';
            if (!preg_match($regx, $url, $matches) || count($matches) != 3)
                die("Error:  Can't parse url regex '$url'");

            $host = $matches[1];

            if ($host == '127.0.0.1') {
                $host = $localhost;
            }

            $port = $matches[2];
        } else {
            $host = $data['redis']['host'];
            if (strpos($host, '/') !== false) {
                return [$host, 0];
            } else {
                return [$host, $data['redis']['port'] ?? 6379];
            }
        }
    }

    return [$host, $port];
}

function getOCPRedisClient($site_url, $user = NULL, $pass = NULL) {
    list($host, $port) = getOCPRedisHostPort($site_url);

    $client = new Redis;

    $client->connect($host, $port);
    if ($user && $pass) {
        if ( ! $client->auth([$user, $pass]))
            panicAbort("Unable to AUTH with username and password");
    } else if ($pass) {
        if ( ! $client->auth($pass))
            panicAbort("Unable to AUTH with password");
    }

    return $client;
}

function isRemoteUnixSock($site_url, &$machine, &$sock) {
    list($host,) = getOCPRedisHostPort($site_url);
    $ocp_machine = getOCPMachine($site_url);
    $loc_machine = getMachine();

    if ($ocp_machine != $loc_machine && strpos($host, '/') !== false) {
        $machine = $ocp_machine;
        $sock = $host;
        return true;
    }

    return false;
}

function getRelayStats($site_url) {
    $uri = "http://$site_url/relay/stats.php";
    $dec = json_decode(file_get_contents($uri), true);
    return $dec;
}

function getIOStats($site_url) {
    $dec = getRelayStats($site_url);
    if ( ! isset($dec['stats']))
        panicAbort("No 'stats' field in relay stats output!");
    $dec = $dec['stats'];
    if ( ! isset($dec['bytes_sent']) || ! isset($dec['bytes_received']))
        panicAbort("Couldn't read IO stats from $site_url");

    return ['sent' => $dec['bytes_sent'], 'received' => $dec['bytes_received']];
}

function getWorkStats($site_url) {
    $dec = getRelayStats($site_url);
    if ( ! isset($dec['stats']))
        panicAbort("Malformed relay stats!");

    return array_intersect_key(
        $dec['stats'],
        array_flip(['command_usec', 'rinit_usec', 'rshutdown_usec', 'sigio_usec'])
    );
}

function getRelayMemory($site_url) {
    $dec = getRelayStats($site_url);
    if ( ! isset($dec['memory']['total']))
        panicAbort("Couldn't read relay.maxmemory stats from $site_url");

    return [$dec['memory']['used'], $dec['memory']['total']];
}

function getRedisCommandStats($site_url, $metric, $user = NULL, $pass = NULL) {
    $res = [];

    if (isRemoteUnixSock($site_url, $machine, $sock)) {
        $info = jsonDecodeCmdOrDie("ssh $machine " . getBinPath($machine) .
                                   "/relay-redis-metrics --cmdstats --unix-socket $sock");

        if ( ! isset($info['data'])) {
            print_r($info);
            panicAbort("Missing DATA from relay-redis-metrics query");
        }

        $info = $info['data'];
        return $info;
    } else {
        $cli = getOCPRedisClient($site_url, $user, $pass);
        $data = $cli->info('commandstats');
        // $re = "/.*$metric=([0-9]+).*/";
        $re = "/(?:,|^)$metric=([0-9]+).*/";
        foreach ($data as $cmd => $info) {
            $cmd = str_replace('cmdstat_', '', $cmd);
            if ( ! preg_match($re, $info, $matches) || count($matches) != 2) {
                panicAbort( "Warning:  '$info' doesn't match regex '$re'\n");
                continue;
            }
            $res[$cmd] = $matches[1];
        }

        return $res;
    }
}

function getRedisUsage($site_url, $user = NULL, $pass = NULL) {
    if (isRemoteUnixSock($site_url, $machine, $sock)) {
        $info = jsonDecodeCmdOrDie("ssh $machine " . getBinPath($machine) .
                                   "/relay-redis-metrics --info --unix-socket $sock");

        if ( ! isset($info['data']['used_memory'])) {
            var_dump($info);
            panicAbort("Missing used_memory field!");
        }

        return $info['data']['used_memory'];
    } else {
        $cli = getOCPRedisClient($site_url, $user, $pass);
        return $cli->info()['used_memory'];
    }
}

function getBinPath($machine) {
    switch (strtolower($machine)) {
        case 'cthulhu':
        case 'ubuntu':
            return "/home/mike/bin";
        case 'mini':
            return "/Users/michaelgrunder/bin";
    }

    panicAbort("Unknown machine '$machine'");
}

function jsonDecodeCmdOrDie($cmd) {
    $res = shell_exec($cmd);
    if ( ! $res)
        panicAbort("Failed to execute command '$cmd'");
    $dec = json_decode($res, true);
    if ( ! $res)
        panicAbort("Failed to decode json from command '$cmd'");
    return $dec;
}

function getOCPRedisInfo($site_url, $user, $pass) {
    if (isRemoteUnixSock($site_url, $machine, $sock)) {
        $info = jsonDecodeCmdOrDie("ssh $machine " . getBinPath($machine) .
                                   "/relay-redis-metrics --info --unix-socket $sock");
        if ( ! isset($info['data'])) {
            var_dump($info);
            panicAbort("Missing 'data' section");
        }

        $info = $info['data'];
    } else {
        $info = getOCPRedisClient($site_url, $user, $pass)->info();
    }

    foreach (['os', 'redis_version', 'run_id', 'used_memory_human', 'used_memory_peak_human'] as $key) {
        if (!isset($info[$key])) {
            var_dump([$sock, $info]);
            panicAbort("Missing redis INFO field '$key'");
        }
    }

    return [
        'os'                     => $info['os'],
        'version'                => $info['redis_version'],
        'run_id'                 => $info['run_id'],
        'used_memory_human'      => $info['used_memory_human'],
        'used_memory_peak_human' => $info['used_memory_peak_human'],
    ];
}

function diffArrays($a1, $a2, $filter = true) {
    $res = [];

    $keys = array_unique(array_merge(array_keys($a1), array_keys($a2)));

    foreach ($keys as $key) {
        $v1 = $a1[$key] ?? 0;
        $v2 = $a2[$key] ?? 0;

        $res[$key] = $v2 - $v1;
    }

    return $filter ? array_filter($res) : $res;
}

function getRelayBinaryId($site_url) {
    $res = file_get_contents("http://$site_url/info.php");
    if ( ! $res)
        panicAbort("Can't execute phpinfo() on $site_url!");

    foreach (explode("\n", $res) as $line) {
        if (strpos($line, 'Binary UUID') === false)
            continue;

        if (preg_match('/.*class="v">([0-9a-f-]{5,40}).*/', $line, $matches) && count($matches) == 2) {
            $bits = explode('-', $matches[1]);
            if (isset($bits[2]) && $bits[1] == 'cafe' && $bits[2] == 'beef')
                return $matches[1];
        }
    }

    return "unknown";
}

//function getRelayGitSha($site_url) {
//    $res = file_get_contents("http://$site_url/info.php");
//    if ( ! $res)
//        panicAbort("Can't execute phpinfo() on $site_url!");
//
//    foreach (explode("\n", $res) as $line) {
//        if (strpos($line,
//    }
//}

function execCmdOrDie($cmd, $check_pattern = NULL) {
    $res = shell_exec($cmd);

    if ($res === NULL)
        panicAbort("Unable to execute command '$cmd'");
    if ($check_pattern !== NULL && !strstr($res, $check_pattern))
        panicAbort("Failed to find '$check_pattern' in '$cmd' output");

    return $res;
}

function flushOCPRedis($site_url, $user = NULL, $pass = NULL) {
    if (isRemoteUnixSock($site_url, $machine, $sock)) {
        execCmdOrDie("ssh $machine redis-cli -s $sock flushall", "OK");
        execCmdOrDie("ssh $machine redis-cli config resetstat", "OK");
    } else {
        $cli = getOCPRedisClient($site_url, $user, $pass);
        $cli->flushdb();
        $cli->rawCommand('config', 'resetstat');
    }
    return true;
}

function getK6Cmd($url, $vus, $duration = '1m', $file = NULL, $details = NULL, $summary = NULL) {
    $script = $file ? 'wp-files.js' : 'wp.js';

    $cmd = "k6 run $script --duration=$duration --vus $vus " .
           "-e SITE_URL=http://$url --insecure-skip-tls-verify";



    if ($file)
        $cmd .= " -e FILENAME=$file";
    if ($details)
        $cmd .= " --out json=$details";
    if ($summary)
        $cmd .= " --summary-export=$summary";

    return $cmd;
}

function getOCPHash($site_url, &$client = NULL, &$prefetch = NULL, &$split = NULL, &$cron = NULL) {
    $cmd = "php update-ocp-settings.php  --site-url $site_url";
    $out = shell_exec($cmd);
    if (!$out) panicAbort("Can't execute command '$cmd'");
    $dec = json_decode($out, true);
    if ( ! $dec) panicAbort("Can't decode json from command '$cmd'");

    $client = $dec['redis']['client'];
    $prefetch = $dec['redis']['prefetch'] ? 'yes' : 'no';
    $split = $dec['redis']['split_alloptions'] ? 'yes' : 'no';
    $cron = $dec['disable_cron'] ? 'yes' : 'no';

    $arr = [
        $dec['redis']['client'] ?? 'unknown',
        $dec['redis']['prefetch'] ? 'prefetch' : 'noprefetch',
        $dec['redis']['split_alloptions'] ? 'split_alloptions' : 'nosplit_alloptions',
        $dec['redis']['compression'],
        $dec['disable_cron'] ? 'nocron' : 'cron'
    ];

    return implode('-', $arr);
}

function getOCPConfig($site_url) {
    $output = [];

    $cmd = "php update-ocp-settings.php --site-url $site_url --json";
    exec($cmd, $output, $exitcode);
    if ($exitcode != 0)
        panicAbort("Can't execute '$cmd'");

    $json = json_decode(implode("\n", $output), true);
    if ( ! $json)
        panicAbort("Can't parse update-ocp-settings.php json output");

    return $json;
}

function updateOCPSettingsCmd($site_url, $client, $prefetch, $split, $compression, $disable_cron) {
    $cmd = "php update-ocp-settings.php ";

    $opt = ["--site-url $site_url"];

    if ($client !== NULL)
        $opt[] = "--client $client ";
    if ($prefetch !== NULL)
        $opt[] = "--prefetch $prefetch ";
    if ($split !== NULL)
        $opt[] = "--split_alloptions $split ";
    if ($compression !== NULL)
        $opt[] = "--compression $compression ";
    if ($disable_cron !== NULL)
        $opt[] = "--disable_cron $disable_cron";

    return $cmd . implode(' ', $opt);
}

function updateOCPSettings($site_url, $client, $prefetch, $split, $compression, $disable_cron) {
    $cmd = updateOCPSettingsCmd($site_url, $client, $prefetch, $split, $compression, $disable_cron);

    exec($cmd, $out, $exitcode);
    if ($exitcode != 0)
        panicAbort("Failed to execute '$cmd'");

    return $cmd;
}

function boolToJsonBool($v) {
    if ($v === NULL)
        return NULL;

    $v = strtolower($v);
    if ($v == 'false')
        return false;
    else if ($v == 'true')
        return true;
    else
        panicAbort("Don't understand json bool '" . print_r($v, true) . "'");
}

function validateOCPSettings($site_url, $client, $prefetch, $split, $compression, $disable_cron) {
    $expected['redis']['client'] = $client;
    $expected['redis']['prefetch'] = boolToJsonBool($prefetch);
    $expected['redis']['split_alloptions'] = boolToJsonBool($split);
    $expected['redis']['compression'] = boolToJsonBool($compression);
    $expected['disable_cron'] = boolToJsonBool($disable_cron);

    $actual = getOCPConfig($site_url);

    foreach ($expected as $k1 => $v1) {
        if (is_array($v1)) {
            foreach ($v1 as $k2 => $exp) {
                $act = $actual[$k1][$k2] ?? NULL;
                if (is_null($act))
                    panicAbort("OCP settings missing ['$k1']['$k2']!");
                else if ($exp !== NULL && $act != $exp) {
                    panicAbort("Expected OCP ['$k1']['$k2'] to be '$exp' but it is '$act'");
                }
            }
        } else {
            $exp = $v1;
            $act = $actual[$k1] ?? NULL;
            if (is_null($act))
                panicAbort("OCP settings missing '$k1'");
            else if ($exp !== NULL && $act != $v1) {
                panicAbort("Expected OCP ['$k1'] to be '$exp' but it is '$act'");
            }
        }
    }
}

function getDateTime() {
    return date('Y-m-d h:i:s', time());
}

function jsonDecodeFileOrDie($file) {
    if ( ! is_file($file) || ! is_readable($file)) {
        panicAbort("Either '$file' doesn't exist or is not readable");
    }

    $arr = json_decode(file_get_contents($file), true);
    if ($arr === false)
        panicAbort("Can't json decode data from file '$file'");

    return $arr;
}

function jsonEncodeOrDie($arr) {
    $enc = json_encode($arr, JSON_PRETTY_PRINT);
    if ( ! $enc)
        panicAbort("Can't encode JSON data after adding to it!");

    return $enc;
}

function jsonEncodeToFileOrDie($file, $data) {
    filePutContentsOrDie($file, json_encode($data));
}

function filePutContentsOrDie($file, $data) {
    if ( ! @file_put_contents($file, $data))
        panicAbort("Can't write to file '$file'");
}

function insertRedisMetrics($file, $cmds, $io_diff, $work_diff, $relay_maxmemory, $used_memory) {
    if ( ! isset($io_diff['sent'])) {
        var_dump($io_diff);
        print_r(debug_backtrace());
        die();
    }

    $arr = jsonDecodeFileOrDie($file);
    $arr['metrics']['redis_commands'] = $cmds;
    $arr['metrics']['redis_used_memory'] = $used_memory;
    $arr['metrics']['redis_bytes_sent'] = $io_diff['sent'];
    $arr['metrics']['redis_bytes_received'] = $io_diff['received'];
    $arr['metrics']['redis_network_io'] = array_sum($io_diff);
    $arr['metrics']['relay_maxmemory'] = $relay_maxmemory;
    $arr['metrics']['relay_cmd_usec'] = $work_diff['command_usec'];
    $arr['metrics']['relay_rinit_usec'] = $work_diff['rinit_usec'];
    $arr['metrics']['relay_rshutdown_usec'] = $work_diff['rshutdown_usec'];
    $arr['metrics']['relay_sigio_usec'] = $work_diff['sigio_usec'];
    filePutContentsOrDie($file, jsonEncodeOrDie($arr));
}

function insertRedisInfo($file, $info) {
    $arr = jsonDecodeFileOrDie($file);
    $arr['redis_info'] = $info;
    filePutContentsOrDie($file, jsonEncodeOrDie($arr));
}

function insertConfigInfo($file, $relay_sha, $client, $prefetch, $split, $compression, $disable_cron, $duration) {
    $arr = jsonDecodeFileOrDie($file);
    if ( ! $arr)
        panicAbort("Can't read metric information from '$file'");

    $arr['config']['relay_sha'] = $relay_sha;
    $arr['config']['client'] = $client;
    $arr['config']['prefetch'] = boolToJsonBool($prefetch);
    $arr['config']['split_alloptions'] = boolToJsonBool($split);
    $arr['config']['disable_cron'] = boolToJsonBool($disable_cron);
    $arr['config']['compression'] = $compression;
    $arr['config']['duration'] = $duration;

    filePutContentsOrDie($file, jsonEncodeOrDie($arr));
}

function cthGetRunningFPMServices($site_url) {
    $services = [];

    $machine = getOCPMachine($site_url);
    $res = execCmdOrDie("ssh $machine systemctl list-units --type=service --state=active");
    foreach (array_filter(explode("\n", $res)) as $unit) {
        if (preg_match('/php([0-9.]+)-fpm.service/', $unit, $matches) && count($matches) == 2) {
            $services[] = $matches[0];
        }
    }

    return $services;
}

function cthFPMStop($site_url) {
    $services = cthGetRunningFpmServices($site_url);
    foreach ($services as $service) {
        shell_exec("ssh cthulhu sudo systemctl stop $service");
    }

    assert(count(cthGetRunningFpmServices($site_url)) == 0);
}

function cthFPMStart($site_url, $version) {
    shell_exec("ssh cthulhu sudo systemctl start php$version-fpm.service");
    assert(cthGetRunningFPMServices($site_url));
}

function cthFPMFile($version) {
    return "/etc/php/$version/fpm/php.ini";
}

function cthModuleIniFile($version, $module) {
    return "/etc/php/$version/mods-available/$module.ini";
}

function cthToggleExtension($php_version, $extension, $enabled) {
    $cfg_dir = "/etc/php/$php_version/mods-available";
    $cfg_file = "$cfg_dir/{$extension}.ini";

    $sed_line = "sed -i '/extension.*=.*$extension.so/c";
    $sed_line .= $enabled ? '' : ';';
    $sed_line .= "extension=$extension.so' $cfg_file";
    $full_line = "ssh cthulhu \"$sed_line\"";
    shell_exec($full_line);
}

function cthRelayInfo($site_url) {
    $info = file_get_contents("http://$site_url/info.php");

    if ( ! $info) panicAbort("Can't get phpinfo");

    $p1 = strpos($info, 'module_relay');
    if ($p1 === false) panicAbort("Relay does not appear to be enabled");
    $info = substr($info, $p1);

    $p2 = strpos($info, '</table>');
    if ($p2 === false) panicAbort("Can't find the end of module_relay section?");
    $info = substr($info, 0, $p2);
    $lines = array_filter(explode("\n", $info));

    $re = '<td class="e">([A-Za-z _.]+)<\/td><td class="v"><?i?>?([A-Z|a-z|0-9-\/. ]+)<.*';

    $res = [];

    foreach ($lines as $line) {
        if (!preg_match("/$re/", $line, $matches))
            continue;

        $res[trim($matches[1])] = $matches[2];
    }

    return $res;
}

function cthUpdateExtensionSetting($php_version, $extension, $setting, $value) {
    assert($php_version && $extension && $setting);

    $cfg_dir = "/etc/php/$php_version/mods-available";
    $cfg_file = "$cfg_dir/{$extension}.ini";

    $sed_line = sprintf("sed -i '/%s.*=/c%s=%s' %s", $setting, $setting, $value, $cfg_file);
    $full_line = sprintf('ssh cthulhu "%s"', $sed_line);
    shell_exec($full_line);
}

function cthSetDictionary($php_version, $value) {
    cthUpdateExtensionSetting($php_version, 'relay', 'relay.zstd_dictionary', $value);
}

function cthVerifyDictionary($site_url, $value) {
    $info = cthRelayInfo($site_url);
    if ( ! isset($info['relay.zstd_dictionary'])) {
        var_dump($info);
        panicAbort("ZSTD dictionary training branch not loaded?");
    }

    $dictionary = strtolower($info['relay.zstd_dictionary']);
    $value = strtolower($value);

    if (strpos($dictionary, $value) === false) {
        panicAbort( "Mismatched zstd dictionary:  Actual: $dictionary, Expected: $value\n");
    }
}

function cthVerifyExtension($site_url, $extension, $enabled) {
    $extension = strtolower($extension);
    $info = file_get_contents("http://$site_url/info.php");
    $term = "module_$extension";
    $pos = strpos($info, $term);

    if ($enabled && $pos === false)
        panicAbort("Expected to find '$term', but it was not found!");
    else if (!$enabled && $pos !== false)
        panicAbort("Expected to NOT find '$term' but it was found!");
}

function cthSetNewRelicAppName($site_url, $php_version, $app_name) {
    $cfg_file = cthModuleIniFile($php_version, 'newrelic');
    $app_name = "OCP $app_name";
    $sed_line = sprintf('sed -i \'/newrelic.appname=/c\newrelic.appname=\"%s\"\' %s',
                        $app_name, $cfg_file);

    $machine = getOCPMachine($site_url);
    shell_exec("ssh cthulhu \"$sed_line\"");

    assert(strpos(execCmdOrDie("ssh $machine cat $cfg_file"), $app_name) !== false);
}

function cthCycleFPM($site_url, $app_name, $disable_newrelic, $client, $zstd_dictionary = NULL) {
    if (getOCPMachine($site_url) != 'cthulhu')
        panicAbort("Error:  Can only cycle fpm if the machine is Linux!");

    cthFPMStop($site_url);

    cthSetNewRelicAppName($site_url, "8.0", $app_name);
    cthToggleExtension("8.0", "newrelic", !$disable_newrelic);
    cthSetDictionary("8.0", $zstd_dictionary);

    if ($client) {
        $isolate_redis = strpos($client, 'redis') !== false;
        cthToggleExtension("8.0", 'relay', !$isolate_redis);
    }

    cthFPMStart($site_url, "8.0");

    if ($client) cthVerifyExtension($site_url, 'relay', !$isolate_redis);
    cthVerifyDictionary($site_url, $zstd_dictionary);
}
