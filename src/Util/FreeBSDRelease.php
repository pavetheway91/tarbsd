<?php declare(strict_types=1);
namespace TarBSD\Util;

class FreeBSDRelease implements \Stringable
{
    const PKG_DOMAIN = 'pkg.freebsd.org';

    public readonly int $major;

    public readonly ?int $minor;

    public readonly string $channel;

    public function __construct(string $release)
    {
        if (preg_match('/^([0-9]{1,2}).([0-9])(-RELEASE|)$/', strtoupper($release), $m))
        {
            $this->major = intval($m[1]);
            $this->minor = intval($m[2]);
            $this->channel = 'RELEASE';
            if ($this->major < 14 || ($this->major == 14 && $this->minor < 3))
            {
                throw new \Exception(sprintf(
                    'FreeBSD %s.%s isn\'t supported',
                    $this->major,
                    $this->minor
                ));
            }
        }
        elseif (preg_match('/^([0-9]{1,2})-(LATEST)$/', strtoupper($release), $m))
        {
            $this->major = intval($m[1]);
            $this->minor = null;
            $this->channel = $m[2];
            if ($this->major < 14)
            {
                throw new \Exception(sprintf(
                    'FreeBSD %s isn\'t supported',
                    $this->major,
                ));
            }
        }
        else
        {
            throw new \Exception(sprintf(
                'failed to parse FreeBSD release %s',
                $release
            ));
        }
    }

    public function getAbi(string $arch) : string
    {
        return sprintf(
            'FreeBSD:%s:%s',
            $this->major,
            $arch
        );
    }

    public function getOsVersion() : string
    {
        $major = strval($this->major);
        $minor = strval(is_int($this->minor) ? $this->minor : 0);
        return str_pad($major, 3, '0', STR_PAD_RIGHT) . str_pad($minor, 4, '0', STR_PAD_RIGHT);
    }

    public function getBaseConf(?string $arch = null) : string
    {
        $keyLocation = ($this->channel == 'LATEST' || $this->major < 15) ? 
            '/usr/share/keys/pkg'
            : ('/usr/share/keys/pkgbase-${VERSION_MAJOR}');

        return sprintf(
            file_get_contents(TARBSD_STUBS . '/FreeBSD-base.conf'),
            $this->getBaseRepo($arch),
            $keyLocation
        );
    }

    public function getBaseRepo(?string $arch = null) : string
    {
        $abi = $arch ? $this->getAbi($arch) : '${ABI}';

        if ($this->channel === 'LATEST')
        {
            return sprintf(
                'https://%s/%s/base_latest',
                self::PKG_DOMAIN,
                $abi
            );
        }
        return sprintf(
            'https://%s/%s/base_release_%d',
            self::PKG_DOMAIN,
            $abi,
            $this->minor
        );
    }

    public function __toString() : string
    {
        return is_int($this->minor) ? sprintf(
            '%s.%s-%s',
            $this->major,
            $this->minor,
            $this->channel
        ) : sprintf(
            '%s-%s',
            $this->major,
            $this->channel
        );
    }
}
