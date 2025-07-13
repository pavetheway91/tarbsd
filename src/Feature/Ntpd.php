<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Ntpd extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'ntpd';

    const PRUNELIST = [
        'usr/sbin/ntpd*'
    ];
}
