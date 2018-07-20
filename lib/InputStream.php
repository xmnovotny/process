<?php

namespace Amp\Process;

use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceOutputStream;

class InputStream implements OutputStream
{
    /** @var ResourceOutputStream */
    private $resourceStream;

    /** @var callable|null */
    private $closeCallback;

    public function __construct(ResourceOutputStream $resourceStream, callable $closeCallback)
    {
        $this->resourceStream = $resourceStream;
        $this->closeCallback = $closeCallback;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @inheritdoc */
    public function write(string $data): void
    {
        $this->resourceStream->write($data);
    }

    /** @inheritdoc */
    public function end(string $finalData = ""): void
    {
        $this->resourceStream->end($finalData);
        $this->close();
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
