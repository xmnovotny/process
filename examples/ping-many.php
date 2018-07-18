<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Process\Process;
use Concurrent\Task;

$stdout = new ResourceOutputStream(STDOUT);

function show_process_output(Process $process): void
{
    global $stdout;

    Amp\ByteStream\pipe($process->getStdout(), $stdout);

    $pid = $process->getPid();
    $exitCode = $process->join();

    $stdout->write("Process {$pid} exited with {$exitCode}" . PHP_EOL);
}

$hosts = ['8.8.8.8', '8.8.4.4', 'google.com', 'stackoverflow.com', 'github.com'];

foreach ($hosts as $host) {
    $command = \DIRECTORY_SEPARATOR === "\\"
        ? "ping -n 5 {$host}"
        : "ping -c 5 {$host}";

    $process = new Process($command);
    $process->start();

    Task::async(function () use ($process) {
        show_process_output($process);
    });
}