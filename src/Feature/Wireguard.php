<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Wireguard extends AbstractFeature
{
    const NAME = 'wireguard';

    const KMODS = [
        'if_wg.ko' => false
    ];

    const PKGS = [
        'wireguard-tools-lite'
    ];
}
