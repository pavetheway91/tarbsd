<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Geli extends AbstractFeature
{
    const NAME = 'geli';

    const KMODS = [
        'geom_eli.ko' => true,
        'cryptodev.ko' => true
    ];

    const PRUNELIST = [
        'sbin/geli'
    ];
}
