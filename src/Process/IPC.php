<?php declare(strict_types=1);
namespace TarBSD\Process;

class IPC
{
    const MESSAGE_QUEUES = [
        IPC\SysvMessageQueue::class => 'sysvmsg',
        IPC\FileMessageQueue::class => null
    ];

    const SEMAPHORES = [
        IPC\SysvSemaphore::class    => 'sysvsem',
        IPC\FlockSemaphore::class   => null
    ];

    public static function getMessageQueue(string $path, string $id) : IPC\MessageQueue
    {
        $class = static::getAvailableMessageQueueClasses()[0];
        return $class::acquire($path, $id);
    }

    public static function acquireSemaphore(string $path, string $id) : IPC\Semaphore
    {
        $class = static::getAvailableSemaphoreClasses()[0];
        return $class::acquire($path, $id);
    }

    public static function acquireSemaphoreBlock(string $path, string $id) : IPC\Semaphore
    {
        $class = static::getAvailableSemaphoreClasses()[0];
        $n = 0;
        do
        {
            try
            {
                return $class::acquire($path, $id);
            }
            catch(IPC\SemaphoreAcquireException $e)
            {
                usleep(50000);
            }
        } while($n++ <= 5);
    }

    protected static function getAvailableMessageQueueClasses() : array
    {
        $out = [];
        foreach(self::MESSAGE_QUEUES as $class => $ext)
        {
            if (!$ext || extension_loaded($ext))
            {
                $out[] = $class;
            }
        }
        return $out;
    }

    protected static function getAvailableSemaphoreClasses() : array
    {
        $out = [];
        foreach(self::SEMAPHORES as $class => $ext)
        {
            if (!$ext || extension_loaded($ext))
            {
                $out[] = $class;
            }
        }
        return $out;
    }
}
