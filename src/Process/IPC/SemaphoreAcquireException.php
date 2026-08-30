<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

class SemaphoreAcquireException extends \Exception
{
    public readonly string $class;

    public static function create(string $class, string $path, string $id, ?SemaphoreAcquireException $prev = null)
    {
        $that = new static(sprintf(
            "class %s failed to aquire a semaphore",
            $class
        ), 0, $prev);
        $that->class = $class;
        return $that;
    }
}
