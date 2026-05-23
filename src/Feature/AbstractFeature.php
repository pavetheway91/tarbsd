<?php declare(strict_types=1);
namespace TarBSD\Feature;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

abstract class AbstractFeature
{
    const KMODS = [];

    const PRUNELIST = [];

    const PKGS = [];

    public function __construct(private readonly bool $enabled)
    {
    }

    public function isEnabled() : bool
    {
        return $this->enabled;
    }

    public function getName() : string
    {
        return static::NAME;
    }

    public function getKmods() : array
    {
        return static::KMODS;
    }

    public function getPackages() : array
    {
        return static::PKGS;
    }

    public function prune(string $dir, Filesystem $fs) : void
    {
        $pruneList = [];

        foreach(static::PRUNELIST as $line)
        {
            $pruneList[] = 'rm -rf ' . $line;
        }

        Process::fromShellCommandline(implode("\n", $pruneList), $dir)->mustRun();
    }
}
