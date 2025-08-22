<?php declare(strict_types=1);
namespace TarBSD\Util;

class BaseRelease implements \Stringable
{
    public readonly int $major;

    public readonly int $minor;

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
        else
        {
            throw new \Exception(sprintf(
                'failed to parse FreeBSD release %s',
                $release
            ));
        }
    }

    public function __toString() : string
    {
        return sprintf(
            '%s.%s-%s',
            $this->major,
            $this->minor,
            $this->stability
        );
    }
}
