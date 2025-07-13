<?php declare(strict_types=1);
namespace TarBSD\Feature;

/***
 * We obviously don't include kernel
 * modules for every single chip there
 * is, add them manually.
 **/
class Wifi extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'wifi';

    const PRUNELIST = [
        'usr/sbin/wpa_*',
    ];
}
