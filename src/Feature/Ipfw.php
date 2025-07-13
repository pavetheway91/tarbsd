<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Ipfw extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'ipfw';

    const KMODS = [
        'ipfw*' => false
    ];

    const PRUNELIST = [
        'sbin/ipfw*'
    ];
}
