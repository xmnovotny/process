<?php

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Loop;
use Amp\Process\Process;
use function Amp\ByteStream\buffer;

if (DIRECTORY_SEPARATOR === "\\") {
    echo "This example doesn't work on Windows." . PHP_EOL;
    exit(1);
}

$process = new Process('cat');
$process->start();

$process->getStdin()->end("abc" . PHP_EOL);

echo buffer($process->getStdout());

$exitCode = $process->join();
echo "Process exited with {$exitCode}." . PHP_EOL;
