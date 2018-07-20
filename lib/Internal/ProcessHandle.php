<?php

namespace Amp\Process\Internal;

use Amp\Process\InputStream;
use Amp\Process\OutputStream;
use Amp\Struct;

abstract class ProcessHandle
{
    use Struct;

    /** @var InputStream */
    public $stdin;

    /** @var OutputStream */
    public $stdout;

    /** @var OutputStream */
    public $stderr;

    /** @var int */
    public $pid;

    /** @var int */
    public $status = ProcessStatus::STARTING;

    /** @var int */
    public $openPipes = 0;
}
