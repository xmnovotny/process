<?php

namespace Amp\Process;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\ResourceInputStream;

class ProcessInputStream implements InputStream
{
    /** @var ResourceInputStream */
    private $resourceStream;

    /** @var string|null */
    private $buffer;

    public function __construct(ResourceInputStream $resourceStream)
    {
        $this->resourceStream = $resourceStream;
    }

    /** @inheritdoc */
    public function read(): ?string
    {
        if ($this->buffer !== null) {
            $buffer = $this->buffer;
            $this->buffer = null;
            return $buffer;
        }

        // FIXME: If we read() here and close() during the pending read, things break
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
        if ($this->resourceStream->getResource()) {
            $this->buffer .= @\stream_get_contents($this->resourceStream->getResource());
            if ($this->buffer === "") {
                $this->buffer = null;
            }
        }

        $this->resourceStream->close();
    }
}
