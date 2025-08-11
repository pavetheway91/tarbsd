<?php declare(strict_types=1);
namespace TarBSD\Feature;

class Resque extends AbstractFeature
{
    const DEFAULT = false;

    const NAME = 'resque';

    const PRUNELIST = [
        'rescue'
    ];
}
