<?php declare(strict_types=1);
namespace TarBSD\Util\WrkFs;

use Symfony\Component\Process\Process;

trait ZFSTrait
{
    public function rollback(string $snapshot) : void
    {
        Process::fromShellCommandline(
            'zfs rollback -r ' . $this->id . '/root@' . $snapshot
        )->mustRun();
    }

    public function snapshot(string $snapshot) : void
    {
        Process::fromShellCommandline(
            'zfs snapshot -r ' . $this->id . '/root@' . $snapshot
        )->mustRun();
    }

    public function destroySnapshot(string $snapshot) : void
    {
        Process::fromShellCommandline(
            'zfs destroy -r ' . $this->id . '/root@' . $snapshot
        )->run();
    }

    public function hasSnapshot(string $snapshot) : bool
    {
        try
        {
            Process::fromShellCommandline('zfs get all ' . $this->id . '/root@' . $snapshot)->mustRun();
        }
        catch(\Exception $e)
        {
            return false;
        }
        return true;
    }

    public function getAvailableMemory() : int
    {
        $avail = trim(
            Process::fromShellCommandline($cmd = sprintf(
                'zfs list -Hp -o available -d 0 -p %s',
                $this->id
            ))->mustRun()->getOutput(),
            "\n"
        );
        return (int) ceil($avail / 1048576);
    }

    public function tightCompression(bool $setting) : void
    {
        Process::fromShellCommandline(sprintf(
            "zfs set compression=%s %s/root",
            $setting ? 'zstd' : 'lz4',
            $this->id
        ))->mustRun();
    }

    private static function getId(string $dir) : string
    {
        return 'tarbsd_' . substr(md5(
            realpath($dir) . '/wrk'
        ), 0, 8);
    }
}
