<?php declare(strict_types=1);
namespace TarBSD;

use Symfony\Component\Yaml\Yaml;

class GlobalConfiguration
{
    const TMPL = <<<TMPL
# how many log files should be kept?
log_rotate: %d

# work file system
#   - zfs-memory is quicker on subsequent builds thanks to snapshots
#   - tmpfs is quicker on first build and easier on cpu
#   - default value 'null' defaults to zfs-memory if zfs is available
fs_type: %s

TMPL;

    const FILE = TARBSD_PREFIX . '/etc/tarbsd.conf';

    private string $hash;

    public int $logRotate;

    public ?string $fsType;

    public function __construct()
    {
        $data = '[]';
        if (file_exists(self::FILE))
        {
            $data = file_get_contents(self::FILE);
        }
        $this->hash = md5($data);
        $data = Yaml::parse($data);

        $this->logRotate = $data['log_rotate'] ?? 10;
        $this->fsType = $data['fs_type'] ?? null;

        if (is_string($this->fsType) && !in_array($this->fsType, $arr = ['zfs-memory', 'tmpfs']))
        {
            throw new \Exception(sprintf(
                'invalid fs_type option %s, valid options %s',
                $this->fsType,
                implode(", ", $arr)
            ));
        }
    }

    public function __debugInfo() : array
    {
        return [
            'file'          => self::FILE,
            'log_rotate'    => $this->logRotate,
            'fs_type'       => $this->fsType
        ];
    }

    public function __destruct()
    {
        $str = sprintf(
            self::TMPL,
            $this->logRotate,
            $this->fsType
        );

        if (md5($str) !== $this->hash)
        {
            @file_put_contents(self::FILE, $str);
        }
    }
}
