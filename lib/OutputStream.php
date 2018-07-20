<?php

namespace Amp\Process;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\ResourceInputStream;

class OutputStream implements InputStream
{
    /** @var ResourceInputStream */
    private $resourceStream;

    /** @var callable|null */
    private $closeCallback;

    public function __construct(ResourceInputStream $resourceStream, callable $closeCallback)
    {
        $this->resourceStream = $resourceStream;
        $this->closeCallback = $closeCallback;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @inheritdoc */
    public function read(): ?string
    {
        return $this->resourceStream->read();
    }

    public function reference(): void
    {
        $this->resourceStream->reference();
    }

    public function unreference(): void
    {
        $this->resourceStream->unreference();
    }

    public function close(): void
    {
        $this->resourceStream->close();
        if ($this->closeCallback !== null) {
            ($this->closeCallback)();
            $this->closeCallback = null;
        }
    }
}
