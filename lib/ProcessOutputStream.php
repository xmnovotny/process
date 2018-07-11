<?php

namespace Amp\Process;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Promise;

class ProcessOutputStream implements OutputStream
{
    /** @var \SplQueue */
    private $queuedWrites;

    /** @var bool */
    private $shouldClose = false;

    /** @var ResourceOutputStream */
    private $resourceStream;

    /** @var StreamException|null */
    private $error;

    public function __construct(Promise $resourceStreamPromise)
    {
        $this->queuedWrites = new \SplQueue;
        $resourceStreamPromise->onResolve(function (?\Throwable $error, ?ResourceOutputStream $resourceStream) {
            if ($error) {
                $this->error = new StreamException("Failed to launch process", 0, $error);

                while (!$this->queuedWrites->isEmpty()) {
                    /** @var Deferred $deferred */
                    [, $deferred] = $this->queuedWrites->shift();
                    $deferred->fail($this->error);
                }

                return;
            }

            \assert($resourceStream !== null);

            while (!$this->queuedWrites->isEmpty()) {
                /** @var string $data */
                /** @var Deferred $deferred */
                [$data, $deferred] = $this->queuedWrites->shift();
                $deferred->resolve($resourceStream->write($data));
            }

            $this->resourceStream = $resourceStream;

            if ($this->shouldClose) {
                $this->resourceStream->close();
            }
        });
    }

    /** @inheritdoc */
    public function write(string $data): void
    {
        if ($this->resourceStream) {
            $this->resourceStream->write($data);
            return;
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $deferred = new Deferred;
        $this->queuedWrites->push([$data, $deferred]);

        Promise\await($deferred->promise());
    }

    /** @inheritdoc */
    public function end(string $finalData = ""): void
    {
        if ($this->resourceStream) {
            $this->resourceStream->end($finalData);
            return;
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $deferred = new Deferred;
        $this->queuedWrites->push([$finalData, $deferred]);

        $this->shouldClose = true;

        Promise\await($deferred->promise());
    }

    public function close(): void
    {
        $this->shouldClose = true;

        if ($this->resourceStream) {
            $this->resourceStream->close();
        } elseif (!$this->queuedWrites->isEmpty()) {
            $error = new ClosedException("Stream closed.");
            do {
                /** @var Deferred $deferred */
                [, $deferred] = $this->queuedWrites->shift();
                $deferred->fail($error);
            } while (!$this->queuedWrites->isEmpty());
        }
    }
}
