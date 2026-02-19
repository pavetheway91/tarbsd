<?php declare(strict_types=1);
namespace TarBSD\Feature;

class BsdInstall extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'bsdinstall';

    const PRUNELIST = [
        'usr/sbin/bsdinstall',
        'usr/libexec/bsdinstall',
        'boot/pmbr',
        'boot/gptboot',
        'boot/gptzfsboot',
    ];

    const PKGS = [
        'pkg'
    ];
}
