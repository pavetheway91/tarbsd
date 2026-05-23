<?php declare(strict_types=1);
namespace TarBSD\Feature;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class Locales extends AbstractFeature
{
    const NAME = 'locales';

    public function prune(string $dir, Filesystem $fs) : void
    {
        foreach([
            $dir . '/usr/share/locale',
            $dir . '/usr/local/share/locale'
        ] as $localeDir) {
            if ($fs->exists($localeDir))
            {
                $f = (new Finder)
                    ->directories()
                    ->in($localeDir)
                    ->notName(['en_*', 'C.UTF*']);
                $fs->remove($f);
            }
        }
    }
}
