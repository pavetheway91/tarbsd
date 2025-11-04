<?php declare(strict_types=1);
namespace TarBSD\Util;

class FreeBSDRelease implements \Stringable
{
    //const PKGBASE_DOMAIN = 'pkgbase.freebsd.org';
    const PKGBASE_DOMAIN = 'pkg.freebsd.org';

    const PKGBASE_DOMAIN_UNOFFICIAL = 'pkg.freebsd.org';

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
            if ($this->major < 14 || ($this->major == 14 && $this->minor < 2))
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

    public function getBaseConf() : string
    {
        return sprintf(
            file_get_contents(TARBSD_STUBS . '/FreeBSD-base.conf'),
            $this->getBaseRepo('${ABI}')
        );
    }

    public function getDomain() : string
    {
        return (is_int($this->minor) && $this->major >= 15)  ?
            self::PKGBASE_DOMAIN
            : self::PKGBASE_DOMAIN_UNOFFICIAL;
    }

    public function getBaseRepo(string $abi) : string
    {
        if ($this->channel === 'LATEST')
        {
            return sprintf(
                'https://%s/%s/base_latest/',
                self::PKGBASE_DOMAIN_UNOFFICIAL,
                $abi
            );
        }
        return sprintf(
            'https://%s/%s/base_release_%d/',
            $this->getDomain(),
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
