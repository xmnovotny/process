<?php

namespace Amp\Process\Test;

use Amp\ByteStream\Message;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Concurrent\Task;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

class ProcessTest extends TestCase
{
    private const CMD_PROCESS = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo foo" : "echo foo";
    private const CMD_PROCESS_SLOW = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c ping -n 3 127.0.0.1 > nul" : "sleep 2";

    /**
     * @expectedException \Amp\Process\StatusError
     */
    public function testMultipleExecution(): void
    {
        $process = new Process(self::CMD_PROCESS);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();
    }

    public function testIsRunning(): void
    {
        $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();

        $awaitable = Task::async([$process, 'join']);
        $this->assertTrue($process->isRunning());

        Task::await($awaitable);
        $this->assertFalse($process->isRunning());
    }

    public function testExecuteResolvesToExitCode(): void
    {
        $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "echo 'foo'; sleep 2; exit 42");
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();

        Task::async(function () use ($process) { $process->getStdout()->read(); });
        $code = $process->join();

        $this->assertSame(42, $code);
        $this->assertFalse($process->isRunning());
    }

    public function testCommandCanRun(): void
    {
        $process = new Process(self::CMD_PROCESS);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();

        $this->assertSame(0, $process->join());
    }

    public function testProcessCanTerminate(): void
    {
        if (\DIRECTORY_SEPARATOR === "\\") {
            $this->markTestSkipped("Signals are not supported on Windows");
        }

        $process = new Process(self::CMD_PROCESS_SLOW);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->signal(0);

        $this->assertSame(0, $process->join());
    }

    public function testGetWorkingDirectoryIsDefault(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame(getcwd(), $process->getWorkingDirectory());
    }

    public function testGetWorkingDirectoryIsCustomized(): void
    {
        $process = new Process(self::CMD_PROCESS, __DIR__);
        $this->assertSame(__DIR__, $process->getWorkingDirectory());
    }

    public function testGetEnv(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->getEnv());
    }

    public function testProcessEnvIsValid(): void
    {
        $process = new Process(self::CMD_PROCESS, null, [
            'test' => 'foobar',
            'PATH' => \getenv('PATH'),
            'SystemRoot' => \getenv('SystemRoot') ?: '', // required on Windows for process wrapper
        ]);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();
        $this->assertSame('foobar', $process->getEnv()['test']);
        $process->join();
    }

    /**
     * @expectedException \Error
     */
    public function testProcessEnvIsInvalid(): void
    {
        new Process(self::CMD_PROCESS, null, [
            ['error_value'],
        ]);
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStdinIsStatusError(): void
    {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdin();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStdoutIsStatusError(): void
    {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdout();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStderrIsStatusError(): void
    {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStderr();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cloning is not allowed!
     */
    public function testProcessCantBeCloned(): void
    {
        $process = new Process(self::CMD_PROCESS);
        /** @noinspection PhpExpressionResultUnusedInspection */
        clone $process;
    }

    public function testKillImmediately(): void
    {
        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage("ended unexpectedly");

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();
        $process->kill();
        $process->join();
    }

    public function testKillThenReadStdout(): void
    {
        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();

        delay(100); // Give process a chance to start, otherwise a different error is thrown.

        $process->kill();

        $this->assertNull($process->getStdout()->read());

        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage("ended unexpectedly");

        $process->join();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithJoin(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $process->join();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithGetPid(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $process->getPid();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithKill(): void
    {
        $process = new Process(self::CMD_PROCESS);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->kill();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithSignal(): void
    {
        $process = new Process(self::CMD_PROCESS);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->signal(0);
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasBeenStarted(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $process->join();
    }

    public function testCommand(): void
    {
        $process = new Process([self::CMD_PROCESS]);
        $this->assertSame(\implode(" ", \array_map("escapeshellarg", [self::CMD_PROCESS])), $process->getCommand());
    }

    public function testOptions(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->getOptions());
    }

    public function getProcessCounts(): array
    {
        return \array_map(function (int $count): array {
            return [$count];
        }, \range(2, 32, 2));
    }

    /**
     * @dataProvider getProcessCounts
     *
     * @param int $count
     */
    public function testSpawnMultipleProcesses(int $count): void
    {
        $processes = [];
        for ($i = 0; $i < $count; ++$i) {
            $command = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit $i" : "exit $i";
            $processes[] = new Process(self::CMD_PROCESS_SLOW . " && " . $command);
        }

        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $i => $process) {
            $this->assertSame($i, $process->join());
        }
    }

    public function testReadOutputAfterExit(): void
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();

        /** @noinspection PhpUnhandledExceptionInspection */
        $process->getStdin()->write("exit 2");

        $this->assertSame("..", $process->getStdout()->read());
        $this->assertSame(0, $process->join());
    }

    public function testReadOutputAfterExitWithLongOutput(): void
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
        /** @noinspection PhpUnhandledExceptionInspection */
        $process->start();

        $count = 128 * 1024 + 1;

        /** @noinspection PhpUnhandledExceptionInspection */
        $process->getStdin()->write("exit " . $count);

        $this->assertSame(str_repeat(".", $count), (new Message($process->getStdout()))->buffer());
        $this->assertSame(0, $process->join());
    }
}
