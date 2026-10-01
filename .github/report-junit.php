<?php

// Prints a GitHub annotation for each failed test in a JUnit report, so failures are
// readable on the pull request without opening the raw log.

$file = $argv[1] ?? 'junit.xml';
$log = $argv[2] ?? null;

$escapeData = fn (string $v) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $v);
$escapeProperty = fn (string $v) => str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $v);

$xml = is_file($file) ? @simplexml_load_file($file) : false;
if ($xml === false) {
    // The test run crashed before writing a full report: show the end of its output instead.
    $tail = $log && is_file($log) ? implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES), -70)) : 'No output captured.';
    $tail = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $tail);
    echo '::error title=Test run crashed::'.$escapeData(mb_substr($tail, -6000))."\n";
    exit(0);
}

$count = 0;
foreach ($xml->xpath('//testcase[failure or error]') as $case) {
    $node = $case->failure ?: $case->error;
    $title = $case['class'].' > '.$case['name'];
    echo '::error title='.$escapeProperty($title).'::'.$escapeData(mb_substr(trim((string) $node), 0, 4000))."\n";
    if (++$count >= 15) {
        break;
    }
}
echo "{$count} failed test(s) reported.\n";
