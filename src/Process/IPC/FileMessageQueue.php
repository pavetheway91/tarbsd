<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use TarBSD\Process\IPC;

class FileMessageQueue implements MessageQueue
{
    private readonly Semaphore $semaphore;

    private readonly Filesystem $fs;

    private function __construct(
        private readonly string $baseFile,
        private readonly array $semaforeFactory
    ) {
        $this->fs = new Filesystem;
        $this->fs->touch($this->baseFile);
        $this->semaphore = $semaforeFactory($this->baseFile, 'q');
    }

    public static function acquire(string $path, string $id, array $semaforeFactory = [IPC::class, 'acquireSemaphore']) : static
    {
        try
        {
            return new static(sprintf(
                "%starbsd.queue.%s.",
                $tmpDir = sys_get_temp_dir(),
                rtrim(str_replace('/', '-', base64_encode(hash_hmac('sha1', $path, $id, true))), '=')
            ), $semaforeFactory);
        }
        catch(SemaphoreAcquireException $e)
        {
            throw SemaphoreAcquireException::create(
                static::class,
                $path,
                $id,
                $e
            );
        }
    }

    public function release() : bool
    {
        foreach(array_merge(range(0, static::MAX_MESSAGES - 1), ['']) as $n)
        {
            try
            {
                $this->fs->remove($this->baseFile . $n);
            }
            catch(IOException $e)
            {}
        }
        return $this->semaphore->release();
    }

    public function send(int $type, string $msg) : bool
    {
        if (0 >= $type)
        {
            throw new \InvalidArgumentException;
        }

        try
        {
            $sem = $this->acquireSemaphoreBlock();
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
            return false;
        }
    }

    public function receive(int $desiredType, ?int &$receivedType, mixed &$msg) : bool
    {
        if (1 > $desiredType)
        {
            throw new \InvalidArgumentException;
        }

        try
        {
            $sem = $this->acquireSemaphoreBlock();
            foreach(range(static::MAX_MESSAGES, 0) as $n)
            {
                try
                {
                    $contents = $this->fs->readFile($file = $this->baseFile . $n);
                    [$readType, $readMsg] = unserialize($contents, ['allowed_classes' => false]);
                    if ($readType === $desiredType || $desiredType === 0)
                    {
                        $receivedType = $readType;
                        $msg = $readMsg;
                        $this->fs->remove($file);
                        $sem->release();
                        return true;
                    }
                }
                catch(IOException $e)
                {
                }
            }
            $sem->release();
            return false;
        }
        catch(SemaphoreAcquireException $e)
        {
            return false;
        }
    }

    protected function acquireSemaphoreBlock() : Semaphore
    {
        $semaforeFactory = $this->semaforeFactory;
        return $semaforeFactory($this->baseFile, 'm');
    }
}
