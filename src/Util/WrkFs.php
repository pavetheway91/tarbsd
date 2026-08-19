<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Component\Process\Process;
use TarBSD\GlobalConfiguration;

abstract class WrkFs
{
    public static function get(GlobalConfiguration $config, string $dir, bool $init, ?bool &$wasCreated = false) : ?static
    {
        if (static::hasZFS() && ($fs = WrkFs\InMemoryZFS::doGet($config, $dir, false)))
        {
            return $fs;
        }

        if ($fs = WrkFs\Tmpfs::doGet($config, $dir, false))
        {
            return $fs;
        }

        if (!$init)
        {
            return null;
        }

        $wasCreated = true;

        switch($config->fsType)
        {
            case null:
                if (static::hasZFS())
                {
                    return WrkFs\InMemoryZFS::doGet($config, $dir, true);
                }
                return WrkFs\Tmpfs::doGet($config, $dir, true);
            case 'zfs-memory':
                return WrkFs\InMemoryZFS::doGet($config, $dir, true);
            case 'tmpfs':
                return WrkFs\Tmpfs::doGet($config, $dir, true);
            default:
                throw new \Exception('invalid fs_type option ' . $config->fsType);
        }
    }

    public static function hasZFS() : bool
    {
        static $hasZFS;

        if (null === $hasZFS)
        {
            try
            {
                Process::fromShellCommandline('zfs --version')->mustRun();
                $hasZFS = true;
            }
            catch (\Exception $e)
            {
                $hasZFS = false;
            }
        }

        return $hasZFS;
    }

    public function start() : void
    {
        $this->tightCompression(true);
    }

    public function rollback(string $snapshot) : void
    {
    }

    public function snapshot(string $snapshot) : void
    {
    }

    public function destroySnapshot(string $snapshot) : void
    {
    }

    public function hasSnapshot(string $snapshot) : bool
    {
        return false;
    }

    public function tightCompression(bool $setting) : void
    {
    }

    public function getAvailableMemory() : int
    {
        $n = 0;
        $found = null;

        foreach(Misc::df(static::TYPE, $this->mnt, false) as $fs)
        {
            $found = $fs;
            $n++;
        }

        if ($n == 1)
        {
            return $found['avail'];
        }
    }

    abstract public function checkSize(?int $size = null) : void;

    abstract public function destroy() : void;
}
