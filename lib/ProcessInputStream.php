<?php

namespace Amp\Process;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Promise;
use Amp\Success;
use function Amp\Promise\await;

class ProcessInputStream implements InputStream
{
    /** @var Deferred */
    private $initialRead;

    /** @var bool */
    private $shouldClose = false;

    /** @var bool */
    private $referenced = true;

    /** @var ResourceInputStream */
    private $resourceStream;

    /** @var StreamException|null */
    private $error;

    /** @var string|null */
    private $buffer;

    public function __construct(Promise $resourceStreamPromise)
    {
        $resourceStreamPromise->onResolve(function ($error, $resourceStream) {
            if ($error) {
                $this->error = new StreamException("Failed to launch process", 0, $error);
                if ($this->initialRead) {
                    $initialRead = $this->initialRead;
                    $this->initialRead = null;
                    $initialRead->fail($this->error);
                }
                return;
            }

            $this->resourceStream = $resourceStream;

            if (!$this->referenced) {
                $this->resourceStream->unreference();
            }

            if ($this->shouldClose) {
                if ($this->resourceStream->getResource()) {
                    $this->buffer .= \stream_get_contents($this->resourceStream->getResource());
                    if ($this->buffer === "") {
                        $this->buffer = null;
                    }
                }

                $this->resourceStream->close();
            }

            if ($this->initialRead) {
                $initialRead = $this->initialRead;
                $this->initialRead = null;

                if ($this->buffer !== null) {
                    $buffer = $this->buffer;
                    $this->buffer = null;
                    $initialRead->resolve($buffer);
                } else {
                    $initialRead->resolve($this->shouldClose ? null : $this->resourceStream->read());
                }
            }
        });
    }

    /** @inheritdoc */
    public function read(): ?string
    {
        if ($this->initialRead) {
            throw new PendingReadError;
        }

        if ($this->buffer !== null) {
            $buffer = $this->buffer;
            $this->buffer = null;
            return $buffer;
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->resourceStream) {
            return $this->resourceStream->read();
        }

        if ($this->shouldClose) {
            return null; // Resolve reads on closed streams with null.
        }

        $this->initialRead = new Deferred;

        return await($this->initialRead->promise());
    }

    public function reference(): void
    {
        $this->referenced = true;

        if ($this->resourceStream) {
            $this->resourceStream->reference();
        }
    }

    public function unreference(): void
    {
        $this->referenced = false;

        if ($this->resourceStream) {
            $this->resourceStream->unreference();
        }
    }

    public function close(): void
    {
        $this->shouldClose = true;

        if ($this->resourceStream && $this->resourceStream->getResource()) {
            $this->buffer .= \stream_get_contents($this->resourceStream->getResource());
            if ($this->buffer === "") {
                $this->buffer = null;
            }
        }

        if ($this->initialRead) {
            $initialRead = $this->initialRead;
            $this->initialRead = null;

            if ($this->buffer !== null) {
                $buffer = $this->buffer;
                $this->buffer = null;
                $initialRead->resolve($buffer);
            } else {
                $initialRead->resolve(null);
            }
        }

        if ($this->resourceStream) {
            $this->resourceStream->close();
        }
    }
}
