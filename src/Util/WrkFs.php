<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

class WrkFs
{
    public static function init(string $dir, int $size = 2) : bool
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

            $md = trim(
                Process::fromShellCommandline(sprintf(
                    'mdconfig -s %dG',
                    $size)
                )->mustRun()->getOutput(),
                "\n"
            );

            Process::fromShellCommandline(
                'zpool create -o ashift=12 -O tarbsd:md=' . $md . ' -m '
                . $mnt . ' ' . $fsId . ' /dev/' . $md . "\n"
                . 'zfs create -o compression=lz4 -o recordsize=4m '.  $fsId . "/root\n"
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
                    return new class($id, $md, $mnt)
                    {
                        public function __construct(
                            public readonly string $id,
                            public readonly string $md,
                            public readonly string $mnt
                        ) {}

                        public function destroy() : void
                        {
                            Process::fromShellCommandline(sprintf(
                                "zpool destroy -f %s && mdconfig -d -u %s",
                                $this->id, $this->md
                            ))->mustRun();
                        }
                    };
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
}
