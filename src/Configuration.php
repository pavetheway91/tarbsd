<?php declare(strict_types=1);
namespace TarBSD;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Generator;

class Configuration
{
    private readonly array $data;

    public function __construct(
        array $input,
        private readonly string $dir
    ) {
        $data = [
            'root_pwhash' => isset($input['root_pwhash']) ? $input['root_pwhash'] : null,
            'root_sshkey' => isset($input['root_sshkey']) ? $input['root_sshkey'] : null,
            'backup' => isset($input['backup']) ? $input['backup'] : true,
            'busybox' => isset($input['busybox']) ? $input['busybox'] : true,
            'ssh' => isset($input['ssh']) ? $input['ssh'] : null,
            'features' => isset($input['features']) ? $input['features'] : [],
            'modules' => isset($input['modules']) ? $input['modules'] : ['early' => [], 'late' => []],
            'packages' => isset($input['packages']) ? $input['packages'] : [],
        ];

        $featureMap = $this->featureMap();

        foreach($featureMap as $name => $class)
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
            throw new \Exception;
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
}
