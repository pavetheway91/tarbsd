<?php declare(strict_types=1);
namespace TarBSD\Process;

use Symfony\Component\Process\Process as SfProcess;

class IPC
{
    public static function getMessageQueue(string $path, string $id) : IPC\MessageQueue
    {
        $class = static::chooseeMessageQueueClass();
        return $class::get($path, $id);
    }

    public static function acquireSemaphore(string $path, string $id) : IPC\Semaphore
    {
        $class = static::chooseSemaforeClass();
        return $class::acquire($path, $id);
    }

    public static function chooseeMessageQueueClass() : string
    {
        return static::useSysV('sysvmsg')
            ? IPC\SysvMessageQueue::class
            : IPC\FileMessageQueue::class;
    }

    public static function chooseSemaforeClass() : string
    {
        return static::useSysV('sysvsem')
            ? IPC\SysvSemaphore::class
            : IPC\FlockSemaphore::class;
    }

    public static function useSysV(?string $extension = null) : array|bool
    {
        static $use;

        if (null === $use)
        {
            $use = [];
            try
            {
                $res = SfProcess::fromShellCommandline(
                    'sysctl kern.features.sysv_msg kern.features.sysv_sem'
                    . ' security.jail.param.sysvmsg security.jail.param.sysvsem'
                    . ' security.jail.jailed'
                )->mustRun()->getOutput();
            }
            catch(\Throwable $e)
            {
                $res = '';
            }
            foreach(['msg', 'sem'] as $sysVthing)
            {
                $extOk = extension_loaded($ext = 'sysv' . $sysVthing);
                $kernelOk = preg_match('/kern.features.sysv\_' . $sysVthing . ':\s1/', $res);

                if (preg_match('/security.jail.jailed:\s1/', $res))
                {
                    $jailOk = !preg_match('/security.jail.param.sysv' . $sysVthing . '.:\s0/', $res);
                }
                else
                {
                    $jailOk = true;
                }

                $use[$ext] = TARBSD_SYSV_IPC && $kernelOk && $extOk && $jailOk;
            }
        }

        return $extension ? (isset($use[$extension]) && $use[$extension]) : $use;
    }
}
