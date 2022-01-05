<?php
require_once('utils.php');

function getRunFolders() {
    $res = [];

    assert(is_dir('data/summary'));

    foreach (new DirectoryIterator('data/summary') as $file) {
        if ($file->isDot() || !$file->isDir()) {
            continue;
        }

        $res[$file->getMTime()] = $file->getFilename();
    }

    krsort($res);
    return $res;
}

function getRunFolder($run) {
    assert(is_dir('data/summary'));

    if (is_numeric($run))
        $run = "run-$run";
    if (strpos($run,'/') === false)
        $run = "data/summary/$run";

    if (!is_dir($run)) {
        panicAbort("Don't see run '$run'\n");
        exit(1);
    }

    return $run;
}

$opt = getopt('f', ['force:'], $optind);
$force = isset($opt['f']) || isset($opt['force']);

if ($argc < $optind + 2) {
    panicAbort("Usage %s [options] <run> <tag>\n", $argv[0]);
    exit(1);
}


$run = getRunFolder($argv[$optind]);
$tag = $argv[$optind+1];

foreach (new DirectoryIterator($run) as $file) {
    if ($file->isDot() || !$file->isFile())
        continue;

    $dec = jsonDecodeFileOrDie("$run/$file");

    if (!$force && isset($dec['config']['tag'])) {
        fprintf(STDERR, "Error:  Detected tag '%s'.  To overwrite use -f or --force\n", $dec['config']['tag']);
        exit(1);
    }

    assert(isset($dec['config']));

    $dec['config']['tag'] = $tag;
    jsonEncodeToFileOrDie("$run/$file", $dec);
}
