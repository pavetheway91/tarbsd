<?php declare(strict_types=1);
namespace TarBSD\Feature;

abstract class AbstractFeature
{
    const DEFAULT = true;

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

    public function getPruneList() : array
    {
        return static::PRUNELIST;
    }

    public function getPackages() : array
    {
        return static::PKGS;
    }
}
