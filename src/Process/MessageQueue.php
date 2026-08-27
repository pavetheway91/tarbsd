<?php declare(strict_types=1);
namespace TarBSD\Process;

use SysvMessageQueue;

class MessageQueue
{
    final public const NOWAIT = \MSG_IPC_NOWAIT;

    final public const EXCEPT = \MSG_EXCEPT;

    final public const NOERROR = \MSG_NOERROR;

    public readonly int $createdBy;

    private readonly SysvMessageQueue $wrapped;

    public function __construct(
        public readonly int $ftok,
        int $permissions = 0666
    ) {
        $this->wrapped = msg_get_queue($ftok, $permissions);
        $this->createdBy = getmypid();
    }

    public static function get(string $path, string $id, int $permissions = 0666) : static
    {
        return new static(ftok($path, $id), $permissions);
    }

    public static function new(string $path, string $id, int $permissions = 0666) : static
    {
        // this doubles as a poor man's semaphore too
        if (!msg_queue_exists($ftok = ftok($path, $id)))
        {
            return new static($ftok, $permissions);
        }
    }

    public function send(
        int $type,
        string|int|float|bool $message,
        bool $blocking = true,
        ?int &$err = null
    ) : bool {
        return msg_send($this->wrapped, $type, $message, false, $blocking, $err);
    }

    public function receive(
        int $desiredType,
        ?int &$receivedType,
        mixed &$message,
        int $flags = 0,
        ?int &$err = null
    ) : bool {
        return msg_receive($this->wrapped, $desiredType, $receivedType, 1024, $message, false, $flags, $err);
    }

    public function remove() : bool
    {
        return msg_remove_queue($this->wrapped);
    }
}
