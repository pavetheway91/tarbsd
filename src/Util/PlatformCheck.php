<?php declare(strict_types=1);
namespace TarBSD\Util;

class PlatformCheck
{
    public static function run() : void
    {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        set_time_limit(0);

        /**
         * Phar archive starts with similiar checks
         * too but the app can be run without using
         * the phar format.
         */
        if (!str_starts_with(__FILE__, 'phar:/'))
        {
            $issues = [];

            if (($os = php_uname('s')) !== 'FreeBSD')
            {
                $issues[] = 'Unsupported operating system ' . $os;
            }
            if (!(PHP_VERSION_ID >= 80200))
            {
                $issues[] = 'PHP >= 8.2.0 required, you are running ' . PHP_VERSION;
            }
            if (!extension_loaded('zlib'))
            {
                $issues[] = 'PHP extension zlib required';
            }
            if (!extension_loaded('pcntl'))
            {
                $issues[] = 'PHP extension pcntl required';
            }
            if ($issues)
            {
                exit("\n\ttarBSD builder cannot run due to following issues:\n\t\t"
                . implode("\n\t\t", $issues) . "\n\n");
            }
        }
    }
}
