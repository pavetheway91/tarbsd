<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Bhyve extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'bhyve';

    const KMODS = [
        'vmm.ko'       => true,
        'nmdm.ko'      => false,
        'if_bridge.ko' => false,
        'bridgestp.ko' => false
    ];

    const PKGS = [
        'vm-bhyve',
        'bhyve-firmware',
        'grub2-bhyve'
    ];

    const PRUNELIST = [
        'usr/sbin/bhyve*',
        'usr/share/bhyve'
    ];
}
