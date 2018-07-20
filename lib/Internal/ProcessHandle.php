<?php

namespace Amp\Process\Internal;

use Amp\Process\OutputStream;
use Amp\Process\InputStream;
use Amp\Struct;
use Concurrent\Deferred;

abstract class ProcessHandle
{
    use Struct;

    /** @var InputStream */
    public $stdin;

    /** @var OutputStream */
    public $stdout;

    /** @var OutputStream */
    public $stderr;

    /** @var Deferred */
    public $pidDeferred;

    /** @var int */
    public $status = ProcessStatus::STARTING;
}
