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
        foreach(array_keys($this->getVdevs()) as $dev)
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
        Process::fromShellCommandline(sprintf(
            'zpool add %s %s',
            $this->id, Misc::mdCreate($size)
        ))->mustRun();
    }

    protected static function doGet(GlobalConfiguration $config, string $dir, bool $init) : ?static
    {
        $fsId = static::getId($dir);

        $data = json_decode(Process::fromShellCommandline(
            'zfs list -Hp -d 0 -o name,mountpoint --json'
        )->mustRun()->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        foreach($data['datasets'] as $dataset)
        {
            if ($dataset['name'] === $fsId)
            {
                if ($dataset['properties']['mountpoint']['value'] !== $dir . '/wrk')
                {
                    throw new \Exception(sprintf(
                        'zfs mountpoint mismatch for %s, expected %s/wrk, got %s',
                        $fsId,
                        $dir,
                        $dataset['properties']['mountpoint']['value']
                    ));
                }
                return new static($fsId, $dataset['properties']['mountpoint']['value']);
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
