<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Pf extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'pf';

    const KMODS = [
        'pf*' => false
    ];

    const PRUNELIST = [
        'sbin/pfctl',
        'sbin/pflog'
    ];
}
