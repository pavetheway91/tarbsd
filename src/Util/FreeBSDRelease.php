<?php declare(strict_types=1);
namespace TarBSD\Util;

class FreeBSDRelease implements \Stringable
{
    public readonly int $major;

    public readonly ?int $minor;

    public readonly string $stability;

    public function __construct(string $release)
    {
        if (preg_match('/^([0-9]{1,2}).([0-9])-(RELEASE)$/', strtoupper($release), $m))
        {
            $this->major = intval($m[1]);
            $this->minor = intval($m[2]);
            $this->stability = $m[3];
            if ($this->major < 14 || ($this->major == 14 && $this->minor < 2))
            {
                throw new \Exception(sprintf(
                    'FreeBSD %s.%s isn\'t supported',
                    $this->major,
                    $this->minor
                ));
            }
        }
        /*elseif (preg_match('/^([0-9]{1,2})-STABLE$/', strtoupper($release), $m))
        {
            $this->major = intval($m[1]);
            $this->minor = null;
            $this->stability = 'STABLE';
            if ($this->major < 14)
            {
                throw new \Exception(sprintf(
                    'FreeBSD %s isn\'t supported',
                    $this->major,
                ));
            }
        }*/
        else
        {
            throw new \Exception(sprintf(
                'failed to parse FreeBSD release %s',
                $release
            ));
        }
    }

    public function getBaseRepo() : string
    {
        return is_int($this->minor)
            ? ('base_release_' . $this->minor)
            : 'base_latest';
    }

    public function __toString() : string
    {
        return is_int($this->minor) ? sprintf(
            '%s.%s-%s',
            $this->major,
            $this->minor,
            $this->stability
        ) : sprintf(
            '%s-%s',
            $this->major,
            $this->stability
        );
    }
}
