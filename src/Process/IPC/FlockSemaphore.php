<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

class FlockSemaphore extends Semaphore
{
    private readonly string $file;

    private $handle;

    private function __construct()
    {}

    public static function acquire(string $path, string $id) : static
    {
        $file = sprintf(
            "%starbsd.flock.%s",
            $tmpDir = sys_get_temp_dir(),
            rtrim(str_replace('/', '-', base64_encode(hash('xxh128', $path.$id, true))), '=')
        );

        $handle = null;

        set_error_handler(function($type, $msg)
        {
        });
        try
        {
            if (!$handle = fopen($file, 'r+') ?: fopen($file, 'r'))
            {
                if ($handle = fopen($file, 'x'))
                {
                    chmod($file, 0o666);
                }
                elseif (!$handle = fopen($file, 'r+') ?: fopen($file, 'r'))
                {
                    usleep(100);
                    $handle = fopen($file, 'r+') ?: fopen($file, 'r');
                }
            }
        }
        catch(\Throwable $e)
        {
        }
        restore_error_handler();

        if (!$handle)
        {
            if (!is_writable($tmpDir))
            {
                throw new \RuntimeException(sprintf(
                    "tmp directory %s is not writable",
                    $tmpDir
                ));
            }
            throw new \RuntimeException(
                "failed to create a flock semaphore, please try enabling sysvsem extension"
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB))
        {
            fclose($handle);
            throw SemaphoreAcquireException::create(
                static::class,
                $path,
                $id
            );
        }

        $that = new static;
        $that->file = $file;
        $that->handle = $handle;
        return $that;
    }

    public function release() : bool
    {
        $ret = @fclose($this->handle);
        @unlink($this->file);
        return $ret;
    }
}
