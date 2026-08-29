<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

class SysvMessageQueue extends Semaphore implements MessageQueue
{
    private readonly \SysvMessageQueue $wrapped;

    private readonly bool $libdeflate;

    public function __construct(
        public readonly int $ftok
    ) {
        try
        {
            $this->wrapped = msg_get_queue($ftok);
        }
        catch (\TypeError $e)
        {
            throw new \RuntimeException('failed to create message queue');
        }
        $this->libdeflate = extension_loaded('libdeflate');
    }

    public static function get(string $path, string $id) : static
    {
        if (!msg_queue_exists($ftok = ftok($path, $id)))
        {
            return new static($ftok);
        }
    }

    public function send(int $type, string $msg) : bool
    {
        return @msg_send(
            $this->wrapped,
            $type,
            $this->libdeflate ? libdeflate_deflate_compress($msg) : gzdeflate($msg),
            false,
            false
        );
    }

    public function receive(int $desiredType, ?int &$receivedType, mixed &$msg) : bool
    {
        $ret = msg_receive($this->wrapped, $desiredType, $receivedType, 1024, $msg, false, MSG_IPC_NOWAIT);

        if ($ret && is_string($msg))
        {
            $msg = gzinflate($msg);
        }

        return $ret;
    }

    public function release() : bool
    {
        return msg_remove_queue($this->wrapped);
    }
}
