<?php declare(strict_types=1);
namespace TarBSD\Util\WrkFs;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use TarBSD\GlobalConfiguration;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;

class Tmpfs extends WrkFs
{
    const TYPE = 'tmpfs';

    private function __construct(public readonly string $mnt)
    {
    }

    public function start() : void
    {
        foreach(Misc::df(null, $this->mnt, true) as $fs)
        {
            if ($fs['mnt'] !== $this->mnt)
            {
                Process::fromShellCommandline('umount -f ' . $fs['mnt'])->mustRun();
            }
        }
        (new Filesystem)->mkdir($root = $this->mnt . '/root');
        Process::fromShellCommandline('mount -t tmpfs tmpfs ' . $root)->mustRun();
    }

    public function checkSize(?int $size = null) : void
    {
        $size = $size ?: 512;
        $avail = $this->getAvailableMemory();

        if ($avail < $size)
        {
            throw new \Exception(sprintf(
                '%sm of memory needed, %sm available',
                $size,
                $avail
            ));
        }
    }

    public function getAvailableMemory() : int
    {
        $n = 0;
        $found = null;
        foreach(Misc::df('tmpfs', $this->mnt, false) as $fs)
        {
            $found = $fs;
            $n++;
        }
        if ($n == 1)
        {
            return $found['avail'];
        }
        throw new \Exception('multiple tmpfs mounts found for ' . $this->mnt);
    }

    public function destroy() : void
    {
        foreach(Misc::df(null, $this->mnt, true) as $fs)
        {
            Process::fromShellCommandline('umount -f ' . $fs['mnt'])->mustRun();
        }
    }

    protected static function doGet(GlobalConfiguration $config, string $dir, bool $init) : ?static
    {
        $mnt = realpath($dir ) . '/wrk';
        $n = 0;

        foreach(Misc::df('tmpfs', $mnt, false) as $fs)
        {
            if ($fs['mnt'] === $mnt)
            {
                $n++;
            }
        }

        switch($n)
        {
            case 0:
                if (!$init)
                {
                    return null;
                }
                (new Filesystem)->mkdir($mnt);
                Process::fromShellCommandline('mount -t tmpfs tmpfs ' . $mnt)->mustRun();
            case 1:
                return new static($mnt);
            default:
                throw new \Exception('multiple tmpfs mounts found for ' . $mnt);
        }
    }
}
