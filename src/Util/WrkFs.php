<?php declare(strict_types=1);
namespace TarBSD\Util;

use TarBSD\GlobalConfiguration;

abstract class WrkFs
{
    public static function get(GlobalConfiguration $config, string $dir, bool $init) : ?static
    {
        return WrkFs\InMemoryZFS::doGet($config, $dir, $init);
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
