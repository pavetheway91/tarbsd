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

    private array $md;

    private function __construct(
        public readonly string $id,
        string $md,
        public readonly string $mnt
    ) {
        $this->md = explode(',', $md);
    }

    public function destroy() : void
    {
        $mds = [];
        foreach($this->md as $dev)
        {
            $mds[] = sprintf(
                '&& mdconfig -d -u %s',
                $dev
            );
        }
        Process::fromShellCommandline(sprintf(
            "zpool destroy -f %s %s",
            $this->id, implode(' ', $mds)
        ))->mustRun();
    }

    public function checkSize(?int $size = null) : void
    {
        if ($size)
        {
            $needed = $size - $this->getAvailableMemory();
            if ($needed > 0)
            {
                $this->grow(intval(($needed + 32)  * 1.2));
            }
        }
        else
        {
            if ($this->getAvailableMemory() < 512)
            {
                $this->grow(384);
            }
        }
    }

    private function grow(int $size) : void
    {
        $this->md[] = $md = Misc::mdCreate($size);

        Process::fromShellCommandline(sprintf(
            'zpool add %s %s && zfs set tarbsd:md=%s %s',
            $this->id, $md, implode(',', $this->md), $this->id
        ))->mustRun();
    }

    protected static function doGet(GlobalConfiguration $config, string $dir, bool $init) : ?static
    {
        $fsId = static::getId($dir);

        $fs = Process::fromShellCommandline(
            'zfs list -Hp -d 0 -o name,tarbsd:md,mountpoint'
        )->mustRun()->getOutput();

        foreach(explode("\n", $fs) as $line)
        {
            if ($line)
            {
                [$id, $md, $mnt] = explode("\t", $line);
                if ($id === $fsId)
                {
                    if ($mnt !== $dir . '/wrk')
                    {
                        throw new \Exception(
                            'zfs mountpoint mismatch for ' . $id . ', expected ' . $dir . '/wrk, got ' . $mnt
                        );
                    }
                    return new static($id, $md, $mnt);
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
            'zpool create -o ashift=12 -O tarbsd:md=' . $md . ' -O compression=lz4 -m '
            . $mnt . ' ' . $fsId . ' /dev/' . $md . "\n"
            . 'zfs create -o compression=zstd ' .  $fsId . "/root\n"
            . 'zfs create -o compression=lz4 ' .  $fsId . "/cache\n"
            . 'zfs snapshot -r ' . $fsId . "/root@empty \n"
        )->mustRun();
    }
}
