<?php

include \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Process\Process;
use Concurrent\Task;

$stdout = new ResourceOutputStream(STDOUT);

$hosts = ['8.8.8.8', '8.8.4.4', 'google.com', 'stackoverflow.com', 'github.com'];

foreach ($hosts as $host) {
    $command = \DIRECTORY_SEPARATOR === "\\"
        ? "ping -n 5 {$host}"
        : "ping -c 5 {$host}";

    $process = new Process($command);
    $process->start();

    Task::async('Amp\ByteStream\pipe', $process->getStdout(), $stdout);
}
