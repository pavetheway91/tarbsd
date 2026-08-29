<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

class NullMessageQueue implements MessageQueue
{
    public function send(int $type, string $msg) : bool
    {
        return false;
    }

    public function receive(int $desiredType, ?int &$receivedType, mixed &$msg) : bool
    {
        return false;
    }
}
