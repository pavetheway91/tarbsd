<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use TarBSD\Util\ProgressIndicator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use phpseclib3\Math\BigInteger;

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

    public static function truncate(string $file, int $size) : void
    {
        if (!$handle = fopen($file, 'w+'))
        {
            throw new \Exception(sprintf(
                'failed to open %s for write',
                $file
            ));
        }
        if (!ftruncate($handle, $size))
        {
            throw new \Exception(sprintf(
                'failed to truncate %s',
                $file
            ));
        }
        fclose($handle);
    }

    public static function mdCreate(int|string $fileOrSize) : string
    {
        if (is_string($fileOrSize))
        {
            $md = Process::fromShellCommandline(sprintf(
                'mdconfig -f %s',
                $fileOrSize
            ))->mustRun()->getOutput();
        }
        else
        {
            $md = Process::fromShellCommandline(sprintf(
                'mdconfig -s %sm -S 4096',
                $fileOrSize
            ))->mustRun()->getOutput();
        }

        return trim($md, "\n");
    }

    public static function mdDestroy(string $device) : void
    {
        Process::fromShellCommandline(sprintf(
            'mdconfig -d -u %s',
            $device
        ))->mustRun();
    }

    public static function dd(string $from, string $to, ProgressIndicator $progressIndicator) : void
    {
        if (!is_resource($fromHandle = fopen($from, 'r')))
        {
            throw new \Exception(sprintf(
                'failed to open %s for read',
                $from
            ));
        }
        if (!is_resource($toHandle = fopen($to, 'c')))
        {
            throw new \Exception(sprintf(
                'failed to open %s for write',
                $to
            ));
        }
        while($buf = fread($fromHandle, 1024 * 1024))
        {
            if (!is_int(fwrite($toHandle, $buf)))
            {
                throw new \Exception(sprintf(
                    'failed to write to %s',
                    $to
                ));
            }
            $progressIndicator->advance();
        }
        fclose($fromHandle);
        fclose($toHandle);
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
        return (int) ceil($size / 1048576);
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

    public static function genUuid() : string
    {
        $time = microtime(false);
        $time = substr($time, 11).substr($time, 2, 7);

        $time = new BigInteger($time);
        $time = $time->add(new BigInteger('122192928000000000'));
        $time = str_pad($time->toHex(), 16, '0', STR_PAD_LEFT);

        $clockSeq = random_int(0, 0x3FFF);

        $node = sprintf('%06x%06x',
            random_int(0, 0xFFFFFF) | 0x010000,
            random_int(0, 0xFFFFFF)
        );

        return sprintf('%08s-%04s-1%03s-%04x-%012s',
            substr($time, -8),
            substr($time, -12, 4),
            substr($time, -15, 3),
            $clockSeq | 0x8000,
            $node
        );
    }

    private static function fs() : Filesystem
    {
        static $fs;
        return $fs ? $fs : $fs = new Filesystem;
    }
}
