<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Zfs extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'zfs';

    const KMODS = [
        'zfs.ko' => true
    ];

    const PRUNELIST = [
        'sbin/zfs',
        'sbin/zpool',
        'sbin/zfsbootcfg',
        'lib/libzfs*'
    ];
}
