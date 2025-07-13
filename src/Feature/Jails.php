<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Jails extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'jails';

    const KMODS = [
        'if_bridge.ko' => false,
        'bridgestp.ko' => false,
        'if_epair.ko'  => false,
        'fdescfs.ko'   => false, // iocage at least, likes this
        'nullfs.ko'    => false
    ];

    const PRUNELIST = [
        'usr/sbin/jail*',
        'usr/share/jexex',
        'usr/share/jls'
    ];
}
