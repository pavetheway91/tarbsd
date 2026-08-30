<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

class SysvSemaphore implements Semaphore
{
    private function __construct(
        public readonly int $ftok,
        private readonly \SysvSemaphore $wrapped
    ) {
    }

    public static function acquire(string $path, string $id) : static
    {
        try
        {
            $that = new static(
                $ftok = ftok($path, $id),
                sem_get($ftok, 1, 0666, false)
            );
        }
        catch (\Throwable $e)
        {
            throw new \RuntimeException('failed to create sysv semaphore');
        }
        if (!sem_acquire($that->wrapped, true))
        {
            throw SemaphoreAcquireException::create(
                static::class,
                $path,
                $id
            );
        }
        return $that;
    }

    public function release() : bool
    {
        return sem_remove($this->wrapped);
    }
}
