<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use TarBSD\Process\IPC;
use TarBSD\Util\Misc;

class FileMessageQueue implements MessageQueue
{
    // 2 would actually suffice, some future proofing :D
    const MAX_MESSAGES = 5;

    private readonly Filesystem $fs;

    private function __construct(
        private readonly string $baseFile,
        private readonly string $semaphoreClass
    ) {
        $this->fs = new Filesystem;

        if (!$this->release())
        {
            throw new \RuntimeException(sprintf(
                'failed to clear old message queue from %s',
                sys_get_temp_dir()
            ));
        }

        $this->fs->touch($this->baseFile);
    }

    public static function get(string $path, string $id, ?string $semaphoreClass = null) : static
    {
        $semaphoreClass = $semaphoreClass ?? IPC::chooseSemaforeClass();

        if (!class_exists($semaphoreClass))
        {
            throw new \InvalidArgumentException(sprintf(
                "class %s does not exist",
                $semaphoreClass
            ));
        }

        if (!in_array(Semaphore::class, class_parents($semaphoreClass)))
        {
            throw new \InvalidArgumentException(sprintf(
                "class %s must extend %s",
                $semaphoreClass,
                Semaphore::class
            ));
        }

        return new static(sprintf(
            "%starbsd.queue.%s.",
            sys_get_temp_dir(),
            rtrim(str_replace('/', '-', base64_encode(hash('xxh128', $path.$id, true))), '=')
        ), $semaphoreClass);
    }

    public function release() : bool
    {
        $ok = true;

        foreach(array_merge(range(0, static::MAX_MESSAGES - 1), ['']) as $n)
        {
            try
            {
                $this->fs->remove($this->baseFile . $n);
            }
            catch(IOException $e)
            {
                $ok = false;
            }
        }

        return $ok && !$this->fs->exists($this->baseFile);
    }

    public function send(int $type, string $msg) : bool
    {
        if (0 >= $type)
        {
            throw new \InvalidArgumentException;
        }

        try
        {
            $sem = $this->tryAcquire();
            foreach(range(static::MAX_MESSAGES -1, 0) as $n)
            {
                try
                {
                    $this->fs->rename(
                        $this->baseFile . $n,
                        $this->baseFile . $n+1,
                        true
                    );
                }
                catch(IOException $e)
                {}
            }
            $this->fs->dumpFile($this->baseFile . '0' , serialize([$type, $msg]));
            $sem->release();
            return true;
        }
        catch(SemaphoreAcquireException $e)
        {
            // these messages aren't actually misson critical
            Misc::log('msg', 'FileMessageQueue failed to send a message');
            return false;
        }
    }

    public function receive(int $desiredType, string|null &$msg, ?int &$receivedType = null) : bool
    {
        if (1 > $desiredType)
        {
            throw new \InvalidArgumentException;
        }

        try
        {
            $sem = $this->tryAcquire();
            foreach(range(static::MAX_MESSAGES - 1, 0) as $n)
            {
                try
                {
                    $contents = $this->fs->readFile($file = $this->baseFile . $n);
                    [$readType, $readMsg] = unserialize($contents, ['allowed_classes' => false]);
                    if ($readType === $desiredType)
                    {
                        $receivedType = $readType;
                        $msg = $readMsg;
                        $this->fs->remove($file);
                        $sem->release();
                        return true;
                    }
                }
                catch(IOException $e)
                {}
            }
            $receivedType = $msg = null;
            $sem->release();
            return false;
        }
        catch(SemaphoreAcquireException $e)
        {
            $receivedType = $msg = null;
            return false;
        }
    }

    protected function tryAcquire() : Semaphore
    {
        $class = $this->semaphoreClass;
        return $class::tryAcquire($this->baseFile, 'm');
    }
}
