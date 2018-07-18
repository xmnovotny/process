<?php

namespace Amp\Process\Internal\Windows;

use Amp\Loop;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Concurrent\Deferred;
use Concurrent\Task;
use const Amp\Process\BIN_DIR;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class Runner implements ProcessRunner
{
    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    private const WRAPPER_EXE_PATH = PHP_INT_SIZE === 8
        ? BIN_DIR . '\\windows\\ProcessWrapper64.exe'
        : BIN_DIR . '\\windows\\ProcessWrapper.exe';

    private static $pharWrapperPath;

    private $socketConnector;

    private function makeCommand(string $workingDirectory): string
    {
        $wrapperPath = self::WRAPPER_EXE_PATH;

        // We can't execute the exe from within the PHAR, so copy it out...
        if (strncmp($wrapperPath, "phar://", 7) === 0) {
            if (self::$pharWrapperPath === null) {
                self::$pharWrapperPath = \tempnam(\sys_get_temp_dir(), "amphp-process-wrapper-");
                \copy(self::WRAPPER_EXE_PATH, self::$pharWrapperPath);

                \register_shutdown_function(static function () {
                    @\unlink(self::$pharWrapperPath);
                });
            }

            $wrapperPath = self::$pharWrapperPath;
        }

        $result = \sprintf(
            '%s --address=%s --port=%d --token-size=%d',
            \escapeshellarg($wrapperPath),
            $this->socketConnector->address,
            $this->socketConnector->port,
            SocketConnector::SECURITY_TOKEN_SIZE
        );

        if ($workingDirectory !== '') {
            $result .= ' ' . \escapeshellarg('--cwd=' . \rtrim($workingDirectory, '\\'));
        }

        return $result;
    }

    public function __construct()
    {
        $this->socketConnector = new SocketConnector;
    }

    /** @inheritdoc */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): ProcessHandle
    {
        if (\strpos($command, "\0") !== false) {
            throw new ProcessException("Can't execute commands that contain null bytes.");
        }

        $options['bypass_shell'] = true;

        $handle = new Handle;
        $handle->proc = @\proc_open($this->makeCommand($cwd ?? ''), self::FD_SPEC, $pipes, $cwd ?: null, $env ?: null, $options);

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

        $securityTokens = \random_bytes(SocketConnector::SECURITY_TOKEN_SIZE * 6);
        $written = \fwrite($pipes[0], $securityTokens . "\0" . $command . "\0");

        \fclose($pipes[0]);
        \fclose($pipes[1]);

        if ($written !== SocketConnector::SECURITY_TOKEN_SIZE * 6 + \strlen($command) + 2) {
            \fclose($pipes[2]);
            \proc_close($handle->proc);

            throw new ProcessException("Could not send security tokens / command to process wrapper");
        }

        $handle->securityTokens = \str_split($securityTokens, SocketConnector::SECURITY_TOKEN_SIZE);
        $handle->wrapperPid = $status['pid'];
        $handle->wrapperStderrPipe = $pipes[2];

        $stdinDeferred = new Deferred;
        $handle->stdioDeferreds[] = $stdinDeferred;

        $stdoutDeferred = new Deferred;
        $handle->stdioDeferreds[] = $stdoutDeferred;

        $stderrDeferred = new Deferred;
        $handle->stdioDeferreds[] = $stderrDeferred;

        $this->socketConnector->registerPendingProcess($handle);

        $handle->stdin = new ProcessOutputStream(Task::await($stdinDeferred->awaitable()));
        $handle->stdout = new ProcessInputStream(Task::await($stdoutDeferred->awaitable()));
        $handle->stderr = new ProcessInputStream(Task::await($stderrDeferred->awaitable()));

        return $handle;
    }

    /** @inheritdoc */
    public function join(ProcessHandle $handle): int
    {
        /** @var Handle $handle */
        $handle->exitCodeRequested = true;

        if ($handle->exitCodeWatcher !== null) {
            Loop::reference($handle->exitCodeWatcher);
        }

        return Task::await($handle->joinDeferred->awaitable());
    }

    /** @inheritdoc */
    public function kill(ProcessHandle $handle): void
    {
        /** @var Handle $handle */
        // todo: send a signal to the wrapper to kill the child instead?
        if (!\proc_terminate($handle->proc)) {
            throw new ProcessException("Terminating process failed");
        }

        $failStart = false;

        if ($handle->childPidWatcher !== null) {
            Loop::cancel($handle->childPidWatcher);
            $handle->childPidWatcher = null;
            $handle->pidDeferred->fail(new ProcessException("The process was killed"));
            $failStart = true;
        }

        if ($handle->exitCodeWatcher !== null) {
            Loop::cancel($handle->exitCodeWatcher);
            $handle->exitCodeWatcher = null;
            $handle->joinDeferred->fail(new ProcessException("The process was killed"));
        }

        $handle->status = ProcessStatus::ENDED;

        if ($failStart || $handle->stdioDeferreds) {
            $this->socketConnector->failHandleStart($handle, "The process was killed");
        }

        $this->free($handle);
    }

    /** @inheritdoc */
    public function signal(ProcessHandle $handle, int $signo): void
    {
        throw new ProcessException('Signals are not supported on Windows');
    }

    /** @inheritdoc */
    public function destroy(ProcessHandle $handle): void
    {
        /** @var Handle $handle */
        if ($handle->status < ProcessStatus::ENDED && \is_resource($handle->proc)) {
            try {
                $this->kill($handle);
                return;
            } catch (ProcessException $e) {
                // ignore
            }
        }

        $this->free($handle);
    }

    private function free(Handle $handle): void
    {
        if ($handle->childPidWatcher !== null) {
            Loop::cancel($handle->childPidWatcher);
            $handle->childPidWatcher = null;
        }

        if ($handle->exitCodeWatcher !== null) {
            Loop::cancel($handle->exitCodeWatcher);
            $handle->exitCodeWatcher = null;
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

        foreach ($handle->sockets as $socket) {
            @\fclose($socket);
        }

        @\stream_get_contents($handle->wrapperStderrPipe);
        @\fclose($handle->wrapperStderrPipe);

        if (\is_resource($handle->proc)) {
            \proc_close($handle->proc);
        }
    }
}
