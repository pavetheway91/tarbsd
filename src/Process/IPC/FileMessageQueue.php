<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

use TarBSD\Process\IPC;

class FileMessageQueue implements MessageQueue
{
    private function __construct(
        private readonly string $file,
        private readonly string $semaphoreClass
    ) {
        @unlink($this->file);
        touch($this->file);
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
            "%starbsd.queue.%s",
            sys_get_temp_dir(),
            rtrim(str_replace('/', '-', base64_encode(hash('xxh128', $path.$id, true))), '=')
        ), $semaphoreClass);
    }

    public function release() : bool
    {
        return @unlink($this->file);
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
            if (
                is_resource($h = fopen($this->file, 'a'))
                &&
                fwrite($h, serialize([$type, $msg]) . "\n")
            ) {
                fclose($h);
                $sem->release();
                return true;
            }
            $sem->release();
            return false;
        }
        catch(SemaphoreAcquireException $e)
        {
            return false;
        }
    }

    public function receive(int $desiredType, string|null &$msg, ?int &$receivedType = null) : bool
    {
        if (1 > $desiredType)
        {
            throw new \InvalidArgumentException;
        }

        $receivedType = $msg = null;
        $out = false;

        try
        {
            $sem = $this->tryAcquire();
            $tmpFile = $this->file . '.' . bin2hex(random_bytes(8));

            if (
                is_resource($in = fopen($this->file, 'r'))
                &&
                is_resource($tmp = fopen($tmpFile, 'w'))
            ) {
                while($buf = fgets($in))
                {
                    $writeBack = true;
                    if (!$out)
                    {
                        [$readType, $readMsg] = unserialize(
                            substr($buf, 0, strlen($buf) - 1),
                            ['allowed_classes' => false]
                        );
                        if ($readType === $desiredType)
                        {
                            $out = true;
                            $msg = $readMsg;
                            $receivedType = $readType;
                            $writeBack = false;
                        }
                    }
                    if ($writeBack)
                    {
                        fwrite($tmp, $buf);
                    }
                }
                fclose($in);
                fclose($tmp);
                rename($tmpFile, $this->file);
            }
            $sem->release();
            return $out;
        }
        catch(SemaphoreAcquireException $e)
        {
            return false;
        }
    }

    protected function tryAcquire() : Semaphore
    {
        $class = $this->semaphoreClass;
        return $class::tryAcquire($this->file, 'm');
    }
}
