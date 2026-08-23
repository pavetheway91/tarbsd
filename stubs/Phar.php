<?php declare(strict_types=1);
namespace TarBSD\Compile;

use UnexpectedValueException;
use IteratorAggregate;
use Generator;
use Countable;
use Throwable;

/***
 * Write-only implementation of the phar archive format.
 * 
 * Unlike the phar extension, this creates reproducible
 * archive, because it doesn't timestamp files written with
 * addFromString method. Also, this is quicker.
 * 
 * As a nice little bonus, archive is smaller too thanks
 * to more compact compression.
 ***/
class Phar implements Countable, IteratorAggregate
{
    final const COMPRESSED  = 0x0000F000;

    final const GZ          = 0x00001000;

    final const SHA256      = 0x0003;

    final const SIG         = 0x00010000;

    public bool $compress = true;

    private int $flags = self::SIG;

    private array $files = [];

    final public function count() : int
    {
        return count($this->files);
    }

    final public function getIterator() : Generator
    {
        foreach($this->files as $path => $file)
        {
            yield $path => $file;
        }
    }

    final public function save(string $file, string $stub) : string
    {
        $hash = hash('sha256', serialize(array_map(function($file)
        {
            return $file->toArray();
        }, $this->files)));
        $hash = hash_hmac('sha256', $stub, $hash);

        $uuid = substr($hash, 0, 16);
        $uuid[8] = $uuid[8] & "\x3F" | "\x80";
        $uuid = substr_replace(bin2hex($uuid), '-', 8, 0);
        $uuid = substr_replace($uuid, '-8', 13, 1);
        $uuid = substr_replace($uuid, '-', 18, 0);
        $uuid = substr_replace($uuid, '-', 23, 0);

        $handle = fopen($tmpFile = sys_get_temp_dir() . bin2hex(random_bytes(8)) . '.phar', 'wb');

        fwrite($handle, preg_replace('/__PHAR__ALIAS__/', $uuid, $stub) . "\n");
        fwrite($handle, $this->serializeManifest($uuid));

        foreach($this->files as $pharFile)
        {
            fwrite($handle, $pharFile->contents);
        }

        fwrite($handle, hash_file('sha256', $tmpFile, true) . pack('V', self::SHA256));
        fwrite($handle, 'GBMB');
        fclose($handle);

        try
        {
            $phar = new \Phar($tmpFile, 0, $uuid);
        }
        catch (Throwable $e)
        {
            throw new UnexpectedValueException('Created phar archive cannot be opened');
        }
        if (count($phar) !== count($this))
        {
            throw new UnexpectedValueException(sprintf(
                'Created phar archive contains %d files, %d expected',
                count($phar),
                count($this->files)
            ));
        }

        @unlink($file);
        rename($tmpFile, $file);
        chmod($file, 0555);
        return $uuid;
    }

    final public function addFromString(string $path, string $contents) : void
    {
        $origSize = strlen($contents);
        $crc32 = crc32($contents);
        $flags = 0555;
        $time = 0;

        if ($origSize > 20 && $this->compress)
        {
            $contents = $this->deflate($contents);
            $flags |= self::GZ;
            $this->flags |= self::GZ;
        }

        $this->files[$path] = new class($contents, $origSize, $time, $crc32, $flags)
        {
            public function __construct(
                public readonly string $contents,
                public readonly int $origSize,
                public readonly int $time,
                public readonly int $crc32,
                public readonly int $flags
            ) {}

            public function toArray() : array
            {
                return [
                    $this->contents, $this->origSize,
                    $this->time, $this->crc32,
                    $this->flags
                ];
            }

            public function serializeManifestEntry(string $path) : string
            {
                return pack('V', strlen($path)) . $path . pack('VVVVV',
                    $this->origSize, $this->time, strlen($this->contents),
                    $this->crc32, $this->flags
                ) . pack('V', 0);
            }
        };
    }

    final public function hasFile(string $path) : bool
    {
        return isset($this->files[$path]);
    }

    private function serializeManifest(string $alias) : string
    {
        $compressed = (($this->flags & self::COMPRESSED) === self::COMPRESSED);

        $apiver = [1, 1, 1];

        $out = chr(($apiver[0] << 4) + $apiver[1])
            . chr(($apiver[2] << 4) + ($compressed ? 0x1 : 0));

        $out .= pack('V', $this->flags);
        $out .= pack('V', strlen($alias)) . $alias;
        $out .= pack('V', 0);

        foreach ($this->files as $path => $file)
        {
            $out .= $file->serializeManifestEntry($path);
        }

        return pack('VV', strlen($out) + 4, count($this->files)) . $out;
    }

    protected function deflate(string $payload) : string
    {
        if (extension_loaded('libdeflate'))
        {
            return libdeflate_deflate_compress($payload, 12);
        }
        return gzdeflate($payload, 9);
    }
}
