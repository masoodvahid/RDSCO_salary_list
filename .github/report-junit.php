<?php

// Prints a GitHub annotation for each failed test in a JUnit report, so failures are
// readable on the pull request without opening the raw log.

$file = $argv[1] ?? 'junit.xml';
if (! is_file($file)) {
    echo "No JUnit report found.\n";
    exit(0);
}

$escapeData = fn (string $v) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $v);
$escapeProperty = fn (string $v) => str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $v);

$count = 0;
foreach (simplexml_load_file($file)->xpath('//testcase[failure or error]') as $case) {
    $node = $case->failure ?: $case->error;
    $title = $case['class'].' > '.$case['name'];
    echo '::error title='.$escapeProperty($title).'::'.$escapeData(mb_substr(trim((string) $node), 0, 4000))."\n";
    if (++$count >= 15) {
        break;
    }
}
echo "{$count} failed test(s) reported.\n";
