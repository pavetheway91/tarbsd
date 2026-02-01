<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use TarBSD\Util\ProgressIndicator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Misc
{
    public static function platformCheck() : void
    {
        /**
         * Shouldn't have practical effect, since
         * most of the time gets spent by other
         * prgrams such as pkg and tar, but just in
         * case user has set it to 1 or something.
         */
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
            if (!extension_loaded('filter'))
            {
                $issues[] = 'PHP extension filter required';
            }
            if ($issues)
            {
                exit("\n\ttarBSD builder cannot run due to following issues:\n\t\t"
                . implode("\n\t\t", $issues) . "\n\n");
            }
        }
    }

    /**
     * Unlike /usr/bin/gzip, this gives real-time
     * progress updates allowing progress indicator
     * to spin.
     */
    public static function zlibCompress(string $file, int $level, ProgressIndicator $progressIndicator) : void
    {
        if (!static::fs()->exists($file))
        {
            throw new \RuntimeException(sprintf(
                "%s does not exist",
                $file
            ));
        }

        $in = fopen($file, 'r');
        $out = gzopen($file . '.gz', 'wb' . $level);

        while (!feof($in))
        {
            gzwrite($out, fread($in, 1048576));
            $progressIndicator->advance();
        }

        fclose($in);
        gzclose($out);
        static::fs()->remove($file);
    }

    public static function pigzCompress(string $file, int $level, ProgressIndicator $progressIndicator) : void
    {
        if (!static::fs()->exists($file))
        {
            throw new \RuntimeException(sprintf(
                "%s does not exist",
                $file
            ));
        }

        $out = fopen($file . '.gz', 'wb');

        $p = Process::fromShellCommandline(
            sprintf("pigz -%d -c %s", $level, $file),
            null, null, null, 1800
        )->mustRun(function ($type, $buffer) use ($out, $progressIndicator)
        {
            $progressIndicator->advance();
            fwrite($out, $buffer);
        });
        fclose($out);
        static::fs()->remove($file);
    }

    public static function hasPigz() : bool
    {
        static $hasPigz;

        if (null === $hasPigz)
        {
            try
            {
                Process::fromShellCommandline('pigz -h')->mustRun();
                $hasPigz = true;
            }
            catch (\Exception $e)
            {
                $hasPigz = false;
            }
        }

        return $hasPigz;
    }

    /**
     * Copies contents of one directory to another using tar.
     */
    public static function tarStream(string $from, string $to, OutputInterface $verboseOutput) : void
    {
        Process::fromShellCommandline(
            'tar cf - . | (cd ' . $to . ' && tar xvf -)',
            $from,
            null,
            null, 
            600
        )->mustRun(function ($type, $buffer) use ($verboseOutput)
        {
            $verboseOutput->write($buffer);
        });
    }

    public static function getFileSizeM(string $file) : int
    {
        if (!file_exists($file))
        {
            throw new \RuntimeException(sprintf(
                "%s does not exist",
                $file
            ));
        }
        if (is_dir($file))
        {
            $size = 0;
            $i = new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS);
            foreach(new RecursiveIteratorIterator($i) as $object)
            {
                if ($object->isFile())
                {
                    $size += $object->getSize();
                }
            }
        }
        else
        {
            $size = filesize($file);
        }
        $mbSize = $size / 1048576;
        return (int) number_format($mbSize, 0, '', '');
    }

    public static function encodeTarOptions(array $arr) : string
    {
        /**
         * http_build_query might work too, but I'm not sure
         * if tar likes it's booleans. 
         */
        $out = [];
        foreach($arr as $key => $value)
        {
            switch(gettype($value))
            {
                case 'integer':
                case 'string':
                    $out[] = $key . '=' . $value;
                    break;
                case 'boolean':
                    $out[] = ($value ? '' : '!') . $key;
                    break;
            }
        }
        return implode(',', $out);
    }

    protected static function fs()
    {
        static $fs;
        return $fs ? $fs : $fs = new Filesystem;
    }
}
