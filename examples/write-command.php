<?php

include \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Process\Process;

Amp\Loop::run(function () {
    if (DIRECTORY_SEPARATOR === "\\") {
        echo "This example doesn't work on Windows." . PHP_EOL;
        exit(1);
    }

    $process = new Process('read; echo "$REPLY"');
    yield $process->start();

    /* send to stdin */
    $process->getStdin()->write("abc\n");

    echo yield ByteStream\buffer($process->getStdout());

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
