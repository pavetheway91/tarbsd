<?php declare(strict_types=1);
namespace TarBSD\Process;

use Symfony\Component\Console\Output\StreamOutput;

class Orphanage
{
    private $stream;

    public function __construct(StreamOutput $output)
    {
        $this->stream = $output->getStream();
    }

    public function amIorphan() : bool
    {
        return false === fstat($this->stream);
    }
}
