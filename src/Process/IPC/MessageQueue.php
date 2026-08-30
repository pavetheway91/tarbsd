<?php declare(strict_types=1);
namespace TarBSD\Process\IPC;

interface MessageQueue extends Semaphore
{
    const MAX_MESSAGES = 3;

    public function send(int $type, string $msg) : bool;

    public function receive(int $desiredType, ?int &$receivedType, mixed &$msg) : bool;
}
