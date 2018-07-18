<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\Message;
use Amp\Process\Process;

if (DIRECTORY_SEPARATOR === "\\") {
    echo "This example doesn't work on Windows." . PHP_EOL;
    exit(1);
}

$process = new Process('read; echo "$REPLY"');
$process->start();

/* send to stdin */
$process->getStdin()->write("abc\n");

echo (new Message($process->getStdout()))->buffer();

$exitCode = $process->join();
echo "Process exited with {$exitCode}.\n";
