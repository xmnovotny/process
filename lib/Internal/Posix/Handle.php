<?php

namespace Amp\Process\Internal\Posix;

use Amp\Process\Internal\ProcessHandle;
use Concurrent\Deferred;

/** @internal */
final class Handle extends ProcessHandle
{
    public function __construct()
    {
        $this->startDeferred = new Deferred;
        $this->joinDeferred = new Deferred;
        $this->originalParentPid = \getmypid();
        $this->openPipes = 4;
    }

    /** @var Deferred */
    public $startDeferred;

    /** @var Deferred */
    public $joinDeferred;

    /** @var resource */
    public $proc;

    /** @var resource */
    public $extraDataPipe;

    /** @var string */
    public $extraDataPipeWatcher;

    /** @var string */
    public $extraDataPipeStartWatcher;

    /** @var int */
    public $originalParentPid;
}
