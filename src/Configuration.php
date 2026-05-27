<?php declare(strict_types=1);
namespace TarBSD;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use TarBSD\Util\Misc;
use Generator;

class Configuration
{
    private readonly array $data;

    public function __construct(
        array $input,
        private readonly string $dir
    ) {
        $data = [
            'root_pwhash'   => $input['root_pwhash'] ?? null,
            'root_sshkey'   => $input['root_sshkey'] ?? null,
            'backup'        => $input['backup'] ?? true,
            'busybox'       => $input['busybox'] ?? false,
            'ssh'           => $input['ssh'] ?? null,
            'platform'      => $input['platform'] ?? 'amd64',
            'features'      => $input['features'] ?? [],
            'modules'       => $input['modules'] ?? ['early' => [], 'late' => []],
            'packages'      => $input['packages'] ?? [],
        ];
        foreach(($featureMap = $this->featureMap()) as $name => $class)
        {
            if (isset($data['features'][$name]))
            {
                $data['features'][$name] = new $class(
                    $data['features'][$name]
                );
            }
            else
            {
                $data['features'][$name] = new $class(false);
            }
        }
        if (count($data['features']) > count($featureMap))
        {
            throw new \Exception('unknown feature');
        }
        if (!is_string($data['platform']) || !in_array($data['platform'], ['amd64', 'aarch64']))
        {
            throw new \Exception(
                'unkown platform ' . $data['platform']
            );
        }
        Misc::validatePublicKey($data['root_sshkey'], false);
        if (!in_array($data['ssh'], ['openssh', 'dropbear', null], true))
        {
            throw new \Exception('Invalid SSH program ' . $data['ssh']);
        }
        if (!is_bool($data['busybox']))
        {
            throw new \Exception('Busybox setting must be bool');
        }
        if (!is_bool($data['backup']))
        {
            throw new \Exception('Backup setting must be bool');
        }
        if (!is_string($data['root_pwhash'])
            || (
                !preg_match('/^\$2y\$([0-9]{2})\$([a-zA-Z0-9\.\/]{53})$/', $data['root_pwhash'])
                &&
                !preg_match('/^\$(5|6)\$((rounds\=([0-9]+)\$)|)([a-zA-Z0-9\.\/]+)\$([a-zA-Z0-9\.\/]{43,86})$/', $data['root_pwhash'])
            )
        ) {
            throw new \Exception('Invalid root password hash');
        }
        $this->data = $data;
    }

    public static function get(?string $dir = null) : static
    {
        $dir = realpath($dir ?: getcwd());

        if (!file_exists($file = $dir . '/tarbsd.yml'))
        {
            throw new \Exception(sprintf(
                'Cannot find %s',
                $file
            ));
        }

        return new static(
            Yaml::parseFile($file),
            $dir
        );
    }

    public static function dump(array $data) : string
    {
        $yml = Yaml::dump($data, 4, 4);
        $yml = preg_replace([
            "/\n    early:/",
            "/\n    late:/",
            "/ly\: null/",
            "/late\: null/",
            "/\n    wifi:/",
            "/\n    ntpd:/",
            "/\n    locales:/"
        ], [
            "\n    # kernel modules to be loaded right at boot\n    early:",
            "\n    # kernel modules to be available later\n    late:",
            "ly:", "late:",
            "\n    # wifi kernel modules are not covered by the feature\n    wifi:",
            "\n    # busybox has ntpd too\n    ntpd:",
            "\n    # non-English locales\n    locales:"
        ], $yml);
        return $yml;
    }

    public function getPlatform() : array
    {
        $exploded = explode("-", $this->data['platform']);
        return [
            $exploded[0],
            $this->data['platform']
        ];
    }

    public function getDir() : string
    {
        return $this->dir;
    }

    public function features() : array
    {
        return $this->data['features'];
    }

    public function backup() : bool
    {
        return $this->data['backup'];
    }

    public function getRootPwHash() : string|null
    {
        return $this->data['root_pwhash'];
    }

    public function getRootSshKey() : string|null
    {
        return $this->data['root_sshkey'];
    }

    public function isBusyBox() : bool
    {
        return $this->data['busybox'];
    }

    public function getSSH() : string|null
    {
        return $this->data['ssh'];
    }

    public function getPackages() : array
    {
        return $this->data['packages'] ?: [];
    }

    public function getModules() : array
    {
        if (!$this->data['modules']['late'])
        {
            return [];
        }
        return iterator_to_array($this->suffixModules(
            $this->data['modules']['late']
        ));
    }

    public function getEarlyModules() : array
    {
        if (!$this->data['modules']['early'])
        {
            return [];
        }
        return iterator_to_array($this->suffixModules(
            $this->data['modules']['early']
        ));
    }

    public function __destruct()
    {
        $current = static::hash(Yaml::parseFile($file = $this->dir . '/tarbsd.yml'));

        if (static::hash($this->data) !== $current && App::amIRoot())
        {
            $fs = new Filesystem;

            $data =  $this->data;
            $data['features'] = array_map(function($feature)
            {
                return $feature->isEnabled();
            }, $data['features']);

            $fs->dumpFile(
                $file,
                Configuration::dump($data)
            );

            if (defined('TARBSD_DEBUG') && TARBSD_DEBUG)
            {
                echo "tarbsd.yml updated\n";
            }
        }
    }

    protected function suffixModules(array $modules) : Generator
    {
        foreach($modules as $module)
        {
            if (!str_ends_with($module, '.ko'))
            {
                $module .= '.ko';
            }
            yield $module;
        }
    }

    protected function featureMap() : array
    {
        static $map;
        if (null === $map)
        {
            foreach((new Finder)->files()->in(__DIR__. '/Feature') as $f)
            {
                $ns = __NAMESPACE__ . '\\Feature\\';

                if (is_subclass_of(
                    $name = $ns . $f->getBasename('.php'),
                    Feature\AbstractFeature::class)
                ) {
                    $map[$name::NAME] = $name;
                }
            }
        }
        return $map;
    }

    private static function hash(array $data) : string
    {
        $data['features'] = array_map(function($feature)
        {
            if (is_object($feature))
            {
                return $feature->isEnabled();
            }
            return false !== $feature;
        }, $data['features']);
        ksort($data);

        return md5(serialize($data));
    }
}
