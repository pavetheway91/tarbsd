<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

abstract class Semaphore
{
    final const TRY_MSEC = 20;

    abstract public function release() : bool;

    abstract public static function acquire(string $path, string $id) : static;

    public static function tryAcquire(string $path, string $id, int $attempts = 10) : static
    {
        $n = 0;
        do
        {
            try
            {
                return static::acquire($path, $id);
            }
            catch(SemaphoreAcquireException $e)
            {
                usleep(self::TRY_MSEC * 1000);
            }
        } while($n++ <= $attempts - 1);

        return static::acquire($path, $id);
    }
}
