<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

interface MessageQueue
{
    public function send(int $type, string $msg) : bool;

    public function receive(int $desiredType, string|null &$msg, ?int &$receivedType = null) : bool;

    public function release() : bool;
}
