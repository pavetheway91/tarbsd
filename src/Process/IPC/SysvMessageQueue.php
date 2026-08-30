<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

class SysvMessageQueue implements MessageQueue
{
    const MAX_MESSAGE_SIZE = 1024;

    private readonly bool $libdeflate;

    private function __construct(
        public readonly int $ftok,
        private readonly \SysvMessageQueue $wrapped
    ) {
        $this->libdeflate = extension_loaded('libdeflate');
    }

    public static function acquire(string $path, string $id) : static
    {
        if (msg_queue_exists($ftok = ftok($path, $id)))
        {
            throw SemaphoreAcquireException::create(
                static::class,
                $path,
                $id
            );
        }
        try
        {
            return new static($ftok, msg_get_queue($ftok));
        }
        catch (\Throwable $e)
        {
            throw new \RuntimeException('failed to create sysv message queue');
        }
    }

    public function send(int $type, string $msg) : bool
    {
        if (0 >= $type)
        {
            throw new \InvalidArgumentException;
        }

        $msg = $this->libdeflate ? libdeflate_deflate_compress($msg) : gzdeflate($msg);

        if (strlen($msg) > static::MAX_MESSAGE_SIZE)
        {
            return false;
        }

        $stat = msg_stat_queue($this->wrapped);
        while($stat['msg_qnum'] > static::MAX_MESSAGES)
        {
            msg_receive($this->q, 0, $type, static::MAX_MESSAGE_SIZE, $msg, false, MSG_IPC_NOWAIT);
            $stat = msg_stat_queue($this->wrapped);
        }

        return @msg_send($this->wrapped, $type, $msg, false, false);
    }

    public function receive(int $desiredType, ?int &$receivedType, mixed &$msg) : bool
    {
        if (1 > $desiredType)
        {
            throw new \InvalidArgumentException;
        }

        $ret = @msg_receive(
            $this->wrapped, $desiredType, $receivedType,
            static::MAX_MESSAGE_SIZE, $msg, false, MSG_IPC_NOWAIT
        );

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

    public function __debugInfo() : array
    {
        return [
            'ftok'          => $this->ftok,
            'libdeflate'    => $this->libdeflate,
            'stat'          => msg_stat_queue($this->wrapped)
        ];
    }
}
