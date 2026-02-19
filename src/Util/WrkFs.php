<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Stringable;

final class WrkFs implements Stringable
{
    private array $md;

    public function __construct(
        public readonly string $id,
        string $md,
        public readonly string $mnt
    ) {
        $this->md = explode(',', $md);
    }

    public static function init(string $dir, int $size = 1) : bool
    {
        if (0 >= $size)
        {
            throw new \Exception('pool size must be greater than zero');
        }

        if (!static::get($dir))
        {
            $fsId = static::getId($dir);

            (new Filesystem)->mkdir(
                $mnt = realpath($dir ) . '/wrk'
            );

            $md = Misc::mdCreate($size * 1024);

            Process::fromShellCommandline(
                'zpool create -o ashift=12 -O tarbsd:md=' . $md . ' -O compression=lz4 -m '
                . $mnt . ' ' . $fsId . ' /dev/' . $md . "\n"
                . 'zfs create -o compression=zstd -o recordsize=4m ' .  $fsId . "/root\n"
                . 'zfs create -o compression=lz4 -o recordsize=4m ' .  $fsId . "/cache\n"
                . 'zfs snapshot -r ' . $fsId . "/root@empty \n"
            )->mustRun();
            return true;
        }

        return false;
    }

    public static function get(string $dir) : ?object
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
                    return new static($id, $md, $mnt);
                }
            }
        }

        return null;
    }

    public static function getId(string $dir) : string
    {
        return 'tarbsd_' . substr(md5(
            realpath($dir ) . '/wrk'
        ), 0, 8);
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

    public function tightCompression(bool $setting)
    {
        Process::fromShellCommandline(sprintf(
            "zfs set compression=%s recordsize=%s %s/root",
            $setting ? 'zstd' : 'lz4',
            $setting ? '4m' : '128k',
            $this->id
        ))->mustRun();
    }

    public function checkSize(?int $size = null) : void
    {
        if ($size)
        {
            $needed = $size - $this->getAvailable();
            if ($needed > 0)
            {
                $this->grow(intval(($needed + 32)  * 1.5));
            }
        }
        else
        {
            if ($this->getAvailable() < 768)
            {
                $this->grow(512);
            }
        }
    }

    public function getAvailable() : int
    {
        $avail = trim(
            Process::fromShellCommandline($cmd = sprintf(
                'zfs list -Hp -o available -d 0 -p %s',
                $this->id
            ))->mustRun()->getOutput(),
            "\n"
        );
        return (int) number_format($avail / 1048576, 0, '', '');
    }

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

    public function __toString() : string
    {
        return $this->id;
    }

    private function grow(int $size) : void
    {
        $this->md[] = $md = Misc::mdCreate($size);

        Process::fromShellCommandline(sprintf(
            'zpool add %s %s && zfs set tarbsd:md=%s %s',
            $this->id, $md, implode(',', $this->md), $this->id
        ))->mustRun();
    }
}
