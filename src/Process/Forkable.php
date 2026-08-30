<?php declare(strict_types=1);
namespace TarBSD\Process;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\SignalRegistry\SignalMap;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\Process\Process as SfProcess;
use Symfony\Component\Console\ConsoleEvents;

trait Forkable
{
    final const MSG_TYPE_SIGNAL = 1;

    protected readonly int $parentPid;

    protected array $childPids = [];

    private readonly string $workerFunc;

    protected readonly IPC\MessageQueue $q;

    protected readonly EventDispatcherInterface $dispatcher;

    public function __destruct()
    {
        foreach($this->childPids as $pid => $autoKill)
        {
            if ($autoKill)
            {
                static::sendSignal($pid, \SIGKILL);
            }
        }
    }

    public function handleSignalChild(ConsoleSignalEvent $event) : void
    {
        switch($sigal = $event->getHandlingSignal())
        {
            case \SIGTERM:
            case \SIGINT:
                if (isset($this->q))
                {
                    $this->q->send(self::MSG_TYPE_SIGNAL, sprintf(
                        "%s::%s process terminated with %s signal",
                        static::class,
                        $this->workerFunc,
                        SignalMap::getSignalName($event->getHandlingSignal())
                    ), false);
                }
                static::sendSignal($this->parentPid, $sigal);
                break;
        }
    }

    protected function fork(string $workerFunc, bool $autoKill, bool $registerSignalHandler, ...$args) : int
    {
        $parentPid = getmypid();

        switch($pid = pcntl_fork())
        {
            case -1:
                throw new \RuntimeException(sprintf(
                    "%s::%s fork failed",
                    static::class,
                    $func
                ));
            case 0:
                $this->parentPid = $parentPid;
                $this->workerFunc = $workerFunc;
                if ($registerSignalHandler)
                {
                    $this->dispatcher->addListener(ConsoleEvents::SIGNAL, [$this, 'handleSignalChild']);
                }
                cli_set_process_title(sprintf(
                    "%s::%s",
                    static::class,
                    $workerFunc
                ));
                $this->$workerFunc(...$args);
                exit;
            default:
                $this->childPids[$pid] = $autoKill;
                return $pid;
        }
    }

    protected function amIOrphan() : bool
    {
        if (!isset($this->parentPid))
        {
            throw new \DomainException('not a fork');
        }
        try
        {
            static::sendSignal($this->parentPid, 0);
            return false;
        }
        catch (\RuntimeException $e)
        {
            return true;
        }
    }

    protected static function sendSignal(int $pid, int $signal) : void
    {
        $success = false;

        if (extension_loaded('posix'))
        {
            $success = @posix_kill($pid, $signal);
        }
        else
        {
            try
            {
                SfProcess::fromShellCommandline(sprintf(
                    'kill -%d %d',
                    $signal, $pid
                ))->mustRun();
                $success = true;
            }
            catch(\Exception $e)
            {}
        }

        if (!$success)
        {
            throw new \RuntimeException(sprintf(
                'failed to kill pid %d',
                $pid
            ));
        }
    }
}
