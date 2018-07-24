<?php

include \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\Process\Process;

// "echo" is a shell internal command on Windows and doesn't work.
$command = DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo Hello World!" : "echo 'Hello, world!'";

$process = new Process($command);
$process->start();

$stdout = new ResourceOutputStream(STDOUT);
Amp\ByteStream\pipe($process->getStdout(), $stdout);

$exitCode = $process->join();
echo "Process exited with {$exitCode}." . PHP_EOL;
