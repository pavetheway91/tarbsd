<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

use TarBSD\Util\Misc;

class SysvMessageQueue implements MessageQueue
{
    const MAX_MESSAGE_SIZE = 256;

    private readonly bool $libdeflate;

    private function __construct(
        public readonly int $ftok,
        private readonly \SysvMessageQueue $wrapped
    ) {
        $this->libdeflate = extension_loaded('libdeflate');

        while(msg_receive($wrapped, 0, $type, 1024, $msg, false, MSG_IPC_NOWAIT))
        {
            /**
             * clear existing messages if the previous run failed
             * to clear for some reason
             */
        }
    }

    public static function get(string $path, string $id) : static
    {
        try
        {
            return new static($ftok = ftok($path, $id), msg_get_queue($ftok));
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

        // just one overly long exception message from pkg could fill the queue
        if (strlen($msg) > static::MAX_MESSAGE_SIZE)
        {
            Misc::log('msg', 'SysvMessageQueue refused to send a long message');
            return false;
        }

        $out = @msg_send($this->wrapped, $type, $msg, false, false);
        if (!$out)
        {
            // these messages aren't actually misson critical
            Misc::log('msg', 'SysvMessageQueue failed to send a message');
        }

        return $out;
    }

    public function receive(int $desiredType, string|null &$msg, ?int &$receivedType = null) : bool
    {
        if (1 > $desiredType)
        {
            throw new \InvalidArgumentException;
        }
        $ret = @msg_receive(
            $this->wrapped, $desiredType, $receivedType,
            1024, $msg, false, MSG_IPC_NOWAIT
        );
        if ($ret && is_string($msg))
        {
            $msg = gzinflate($msg);
        }
        if (!$ret)
        {
            $receivedType = $msg = null;
        }
        return $ret;
    }

    public function release() : bool
    {
        return @msg_remove_queue($this->wrapped);
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
