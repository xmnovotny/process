<?php

namespace Amp\Process;

use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceOutputStream;

class InputStream implements OutputStream
{
    /** @var ResourceOutputStream */
    private $resourceStream;

    public function __construct(ResourceOutputStream $resourceStream)
    {
        $this->resourceStream = $resourceStream;
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
    }

    public function close(): void
    {
        $this->resourceStream->close();
    }
}
