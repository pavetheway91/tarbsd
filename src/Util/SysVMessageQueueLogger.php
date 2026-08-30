<?php declare(strict_types=1);
namespace TarBSD\Util;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use SysvMessageQueue;
use Generator;
use Phar;

class SysVMessageQueueLogger implements LoggerInterface
{
    use LoggerTrait;

    private readonly SysvMessageQueue $q;

    private readonly bool $libdeflate;

    public function __construct()
    {
        $this->q = static::getQueue();
        $this->libdeflate = extension_loaded('libdeflate');
    }

    public function log($level, string|\Stringable $message, array $context = []) : void
    {
        $context[0] = getmypid();

        $msg = json_encode([$level, $message, $context]);
        $msg = $this->libdeflate ? libdeflate_deflate_compress($msg) : gzdeflate($msg);

        @msg_send($this->q, 1, $msg, false, false);

        $stat = @msg_stat_queue($this->q);

        while($stat && $stat['msg_qnum'] > 3)
        {
            @msg_receive($this->q, 0, $type, 1024, $msg, false, MSG_IPC_NOWAIT);
            $stat = @msg_stat_queue($this->q);
        }
    }

    public function listen() : Generator
    {
        $q = static::getQueue();

        while(msg_receive($q, 0, $type, 1024, $msg, false, MSG_IPC_NOWAIT))
        {
            // clear existing messages
        }

        while(msg_receive($q, 0, $type, 1024, $msg, false))
        {
            $msg = gzinflate($msg);
            [$level, $message, $context] = json_decode($msg, true);
            $pid = $context[0];
            unset($context[0]);
            yield [$level, $message, $pid, $context];
        }
    }

    public static function getQueue() : SysvMessageQueue
    {
        static $q;
        return $q ?: $q = msg_get_queue(ftok(Phar::running(false), 'l'));
    }
}
