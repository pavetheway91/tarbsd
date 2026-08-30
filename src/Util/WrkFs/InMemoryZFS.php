<?php declare(strict_types=1);
namespace TarBSD\Util\WrkFs;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use TarBSD\GlobalConfiguration;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;

class InMemoryZFS extends WrkFs
{
    use ZFSTrait;

    const TYPE = 'zfs';

    private function __construct(public readonly string $id,public readonly string $mnt)
    {
    }

    public function destroy() : void
    {
        $mds = [];
        foreach($this->getVdevs() as $dev)
        {
            $mds[] = sprintf(
                '&& mdconfig -d -u %s',
                $dev
            );
        }
        Process::fromShellCommandline($cmd = sprintf(
            "zpool destroy -f %s %s",
            $this->id, implode(' ', $mds)
        ))->mustRun();
        Misc::log('zfs', $cmd);
    }

    public function checkSize(int $size, ?int $devSize = null) : void
    {
        $size = $size * 1.1;
        $devSize = $devSize ?: 64;

        while (0 < ($size - $this->getAvailableMemory()))
        {
            $this->grow($devSize);
        }
    }

    private function grow(int $size) : void
    {
        Process::fromShellCommandline($cmd = sprintf(
            'zpool add %s %s',
            $this->id, Misc::mdCreate($size)
        ))->mustRun();
        Misc::log('zfs', $cmd);
    }

    protected static function doGet(GlobalConfiguration $config, string $dir, bool $init) : ?static
    {
        $fsId = static::getId($dir);

        $data = Process::fromShellCommandline(
            'zfs list -Hp -d 0 -o name,mountpoint'
        )->mustRun()->getOutput();

        if ($data)
        {
            foreach(explode("\n", $data) as $line)
            {
                if ($line)
                {
                    [$name, $mnt] = explode("\t", $line);
                    if ($name === $fsId)
                    {
                        if ($mnt !== $dir . '/wrk')
                        {
                            throw new \Exception(sprintf(
                                'zfs mountpoint mismatch for %s, expected %s/wrk, got %s',
                                $fsId,
                                $dir,
                                $mnt
                            ));
                        }
                        return new static($fsId, $mnt);
                    }
                }
            }
        }

        if ($init)
        {
            static::init($dir, $fsId);
            return static::doGet($config, $dir, false);
        }

        return null;
    }

    private static function init(string $dir, string $fsId) : void
    {
        (new Filesystem)->mkdir(
            $mnt = realpath($dir ) . '/wrk'
        );

        $md = Misc::mdCreate(768, true);

        Process::fromShellCommandline(
            'zpool create -o ashift=12 -O compression=lz4 -m '
            . $mnt . ' ' . $fsId . ' /dev/' . $md . "\n"
            . 'zfs create -o compression=zstd ' .  $fsId . "/root\n"
            . 'zfs create -o compression=lz4 ' .  $fsId . "/cache\n"
            . 'zfs snapshot -r ' . $fsId . "/root@empty \n"
        )->mustRun();
    }
}
