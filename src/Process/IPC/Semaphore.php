<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

abstract class Semaphore
{
    abstract public function release() : bool;

    public static function get(string $path, string $id) : static
    {
        return SysvMessageQueue::get($path, $id);
    }
}
