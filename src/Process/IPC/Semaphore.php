<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

interface Semaphore
{
    public static function acquire(string $path, string $id) : static;

    public function release() : bool;
}
