<?php

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Process\InputStream;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\OutputStream;
use Amp\Process\ProcessException;
use Concurrent\Deferred;
use Concurrent\Task;

/** @internal */
final class Runner implements ProcessRunner
{
    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    public static function onProcessEndExtraDataPipeReadable($watcher, $stream, Handle $handle): void
    {
        Loop::cancel($watcher);

        $handle->extraDataPipeWatcher = null;
        $handle->status = ProcessStatus::ENDED;

        if (!\is_resource($stream) || \feof($stream)) {
            $handle->joinDeferred->fail(new ProcessException("Process (pid = {$handle->pid}) ended unexpectedly."));
        } else {
            $exitCode = \rtrim(@\stream_get_contents($stream));
            if (\is_numeric($exitCode)) {
                $handle->joinDeferred->resolve((int) $exitCode);
            } else {
                $handle->joinDeferred->fail(new ProcessException("Process (pid = {$handle->pid}) ended unexpectedly."));
            }
        }

        if (--$handle->openPipes === 0) {
            self::free($handle);
        }
    }

    public static function onProcessStartExtraDataPipeReadable($watcher, $stream, $data): void
    {
        Loop::cancel($watcher);

        $pid = \rtrim(@\fgets($stream));

        /** @var Handle $handle */
        /** @var Deferred[] $deferreds */
        [$handle, $pipes, $deferreds] = $data;

        if (!$pid || !\is_numeric($pid)) {
            $error = new ProcessException("Could not determine PID");
            /** @var $deferreds Deferred[] */
            foreach ($deferreds as $deferred) {
                $deferred->fail($error);
            }

            if ($handle->status < ProcessStatus::ENDED) {
                $handle->status = ProcessStatus::ENDED;
                $handle->joinDeferred->fail($error);
            }

            return;
        }

        $handle->status = ProcessStatus::RUNNING;
        $handle->pid = (int) $pid;

        $deferreds[0]->resolve($pipes[0]);
        $deferreds[1]->resolve($pipes[1]);
        $deferreds[2]->resolve($pipes[2]);

        if ("" !== $exitCode = \rtrim(@\fgets($stream))) {
            $handle->status = ProcessStatus::ENDED;
            $handle->joinDeferred->resolve((int) $exitCode);

            self::free($handle);

            return;
        }

        if ($handle->extraDataPipeWatcher !== null) {
            Loop::enable($handle->extraDataPipeWatcher);
        }
    }

    private static function free(Handle $handle): void
    {
        /** @var Handle $handle */
        if ($handle->status < ProcessStatus::ENDED && \getmypid() === $handle->originalParentPid) {
            @\proc_terminate($handle->proc, 9); // Ignore any failures
        }

        /** @var Handle $handle */
        if ($handle->extraDataPipeWatcher !== null) {
            Loop::cancel($handle->extraDataPipeWatcher);
            $handle->extraDataPipeWatcher = null;
        }

        /** @var Handle $handle */
        if ($handle->extraDataPipeStartWatcher !== null) {
            Loop::cancel($handle->extraDataPipeStartWatcher);
            $handle->extraDataPipeStartWatcher = null;
        }

        if (\is_resource($handle->extraDataPipe)) {
            \fclose($handle->extraDataPipe);
        }

        if ($handle->stdin !== null) {
            $handle->stdin->close();
        }

        if ($handle->stdout !== null) {
            $handle->stdout->close();
        }

        if ($handle->stderr !== null) {
            $handle->stderr->close();
        }

        if (\is_resource($handle->proc)) {
            \proc_close($handle->proc);
        }
    }

    /** @inheritdoc */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): ProcessHandle
    {
        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        $handle = new Handle;
        $handle->proc = @\proc_open($command, self::FD_SPEC, $pipes, $cwd ?: null, $env ?: null, $options);

        if (!\is_resource($handle->proc)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $status = \proc_get_status($handle->proc);

        if (!$status) {
            \proc_close($handle->proc);
            throw new ProcessException("Could not get process status");
        }

        $stdinDeferred = new Deferred;
        $stdoutDeferred = new Deferred;
        $stderrDeferred = new Deferred;

        $handle->extraDataPipe = $pipes[3];

        \stream_set_blocking($pipes[3], false);

        $handle->extraDataPipeStartWatcher = Loop::onReadable($pipes[3], [self::class, 'onProcessStartExtraDataPipeReadable'], [$handle, [
            new ResourceOutputStream($pipes[0]),
            new ResourceInputStream($pipes[1]),
            new ResourceInputStream($pipes[2]),
        ], [
            $stdinDeferred, $stdoutDeferred, $stderrDeferred,
        ]]);

        $handle->extraDataPipeWatcher = Loop::onReadable($pipes[3], [self::class, 'onProcessEndExtraDataPipeReadable'], $handle);

        Loop::unreference($handle->extraDataPipeWatcher);
        Loop::disable($handle->extraDataPipeWatcher);

        $closeCallback = static function () use ($handle) {
            if (--$handle->openPipes === 0) {
                self::free($handle);
            }
        };

        $handle->stdin = new InputStream(Task::await($stdinDeferred->awaitable()), $closeCallback);
        $handle->stdout = new OutputStream(Task::await($stdoutDeferred->awaitable()), $closeCallback);
        $handle->stderr = new OutputStream(Task::await($stderrDeferred->awaitable()), $closeCallback);

        return $handle;
    }

    /** @inheritdoc */
    public function join(ProcessHandle $handle): int
    {
        /** @var Handle $handle */
        if ($handle->extraDataPipeWatcher !== null) {
            Loop::reference($handle->extraDataPipeWatcher);
        }

        return Task::await($handle->joinDeferred->awaitable());
    }

    /** @inheritdoc */
    public function kill(ProcessHandle $handle): void
    {
        /** @var Handle $handle */
        if (!\proc_terminate($handle->proc, 9) && \proc_get_status($handle->proc)['running']) {
            throw new ProcessException("Terminating process (pid = {$handle->pid}) failed.");
        }
    }

    /** @inheritdoc */
    public function signal(ProcessHandle $handle, int $signo): void
    {
        /** @var Handle $handle */
        if (!\proc_terminate($handle->proc, $signo)) {
            throw new ProcessException("Sending signal (signo = {$signo}) to process (pid = {$handle->pid}) failed.");
        }
    }
}
