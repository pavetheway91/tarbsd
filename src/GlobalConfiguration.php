<?php declare(strict_types=1);
namespace TarBSD;

use Symfony\Component\Yaml\Yaml;

class GlobalConfiguration
{
    const FILE = '/usr/local/etc/tarbsd.conf';

    private readonly string $hash;

    public ?int $logRotate;

    public function __construct()
    {
        $data = file_exists(self::FILE) ? Yaml::parseFile(self::FILE) : [];
        ksort($data);
        $this->hash = md5(json_encode($data));

        $this->logRotate = $data['log_rotate'] ?? 10;
    }

    public function toArray() : array
    {
        $arr = [
            'log_rotate' => $this->logRotate,
        ];
        ksort($arr);
        return $arr;
    }

    public function __destruct()
    {
        if (
            $this->hash !== md5(json_encode($arr = $this->toArray()))
            && App::amIRoot()
        ) {
            $yml = Yaml::dump($arr, 4, 4);

            // fail silenlty if it's read-only
            @file_put_contents(self::FILE, $yml);
        }
    }
}
