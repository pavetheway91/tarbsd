<?php declare(strict_types=1);
namespace TarBSD\Compile;
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/ClassOrderer.php';
require __DIR__ . '/Phar.php';

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use TarBSD\Util\Misc;

use SplFileInfo;

abstract class AbstractCompiler extends Command
{
    const BUNDLE_PACKAGES = [
        'Psr\Log'                           => 'psr/log',
        'Psr\EventDispatcher'               => 'psr/event-dispatcher',
        //'Psr\Container'                     => 'psr/container',
        'Psr\Cache'                         => 'psr/cache',
        'Symfony\Contracts\HttpClient'      => 'symfony/http-client-contracts',
        'Symfony\Contracts\EventDispatcher' => 'symfony/event-dispatcher-contracts',
        'Symfony\Contracts\Cache'           => 'symfony/cache-contracts',
        'Symfony\Contracts\Service'         => 'symfony/service-contracts',
        'Symfony\Component\EventDispatcher' => 'symfony/event-dispatcher',
        'Symfony\Contracts\HttpClient'      => 'symfony/http-client-contracts',
        'Symfony\Component\Console'         => 'symfony/console',
        'Symfony\Component\HttpClient'      => 'symfony/http-client',
        'Symfony\Component\Finder'          => 'symfony/finder',
        'Symfony\Component\Process'         => 'symfony/process',
        //'Symfony\Component\VarExporter'     => 'symfony/var-exporter',
        'Symfony\Component\VarDumper'       => 'symfony/var-dumper',
        'Symfony\Component\ErrorHandler'    => 'symfony/error-handler',
        'Symfony\Component\Filesystem'      => 'symfony/filesystem',
        'Symfony\Component\String'          => 'symfony/string',
        'Symfony\Component\Yaml'            => 'symfony/yaml',
        'Symfony\Component\Cache'           => 'symfony/cache',
        'ParagonIE\ConstantTime'            => 'paragonie/constant_time_encoding',
        'phpseclib4'                        => 'phpseclib/phpseclib'
    ];

    const REGEX_LICENSE = '{(license|copying|copyright)(\.[a-z]{2,3}|)$}Di';

    const REGEX_ATTRIBUTE = '{(\#\[(\\\\|)([a-z0-9_]+)(\]|\())}Di';

    private readonly string $root;

    private array $includedFiles = [];

    private array $mergedFiles = [];

    protected bool $bundlePackages;

    protected bool $minify;

    protected readonly Phar $phar;

    public function __construct()
    {
        parent::__construct();
        $this->root = dirname(__DIR__);
        $this->phar = new Phar;
    }

    protected function write(OutputInterface $output, int $start, string $versionTag) : string
    {
        $fs = new Filesystem;
        $fs->mkdir($out = dirname(__DIR__) . '/out');
        $fs->remove((new Finder)->in($out));

        $alias = $this->phar->save($finalFile = $out . '/tarbsd', $this->genPharStub());

        $size = $compressedSize = 0;
        foreach($this->phar as $file)
        {
            $compressedSize +=  strlen($file->contents);
            $size += $file->origSize;
        }

        if (($pharSize = filesize($finalFile)) > 1048576)
        {
            $pharSize = number_format($pharSize / 1048576, 2) . ' MB';
        }
        else
        {
            $pharSize = number_format($pharSize / 1024, 1) . ' KB';
        }

        $output->writeln(sprintf(
            "generated %s\ntime: %s seconds\ntag: %s\nalias: %s\nfiles: %s\nsize: %s",
            $finalFile,
            time() - $start,
            $versionTag,
            $alias,
            count($this->phar),
            $pharSize
        ));

        if ($size !== $compressedSize)
        {
            $output->writeln(sprintf(
                "deflate: %s\ncompress ratio: %.2fX, %0.1f%%",
                extension_loaded('libdeflate') ? 'libdeflate' : 'zlib',
                $size / $compressedSize,
                $compressedSize / $size * 100
            ));
        }

        return $finalFile;
    }

    protected function addBootsraptFiles() : void
    {
        $this->phar->addFromString('bootstrap.php', $this->genBootstrap());
        $this->addFile(__DIR__ . '/../vendor/composer/LICENSE');
        $this->addFile(__DIR__ . '/../vendor/composer/ClassLoader.php');
        $this->addFile(__DIR__ . '/../vendor/composer/installed.json');
        $this->addFile(__DIR__ . '/../vendor/composer/platform_check.php');
    }

    protected function addOwnSrc(
        OutputInterface $output,
        bool $ports,
        string $prefix,
        ?string $versionTag,
        bool $production
    ) : void {
        $this->addFile(__DIR__ . '/../LICENSE');
        $this->addFile(__DIR__ . '/../composer.json');

        $countStart = count($this->phar);
        $output->write("adding files for src ");
        if ($this->bundlePackages)
        {
            $this->phar->addFromString('src/bundle.php', $this->mergeFiles(
                ClassOrderer::orderClasses($this->root . '/src', 'TarBSD'),
                true
            ));
        }
        else
        {
            foreach(
                (new Finder)->files()->files()->in($this->root . '/src')
                as $file
            ) {
                $this->addFile($file);
            }
        }
        $output->writeln(sprintf("%d files", count($this->phar) - $countStart));

        $constants = [];
        $constants['TARBSD_GITHUB_API'] = 'https://api.github.com';
        $constants['TARBSD_SELF_UPDATE'] = (!$ports && $production);
        $constants['TARBSD_PORTS'] = $ports;
        $constants['TARBSD_VERSION'] = $versionTag;
        $constants['TARBSD_PREFIX'] = $prefix;
        $constantsStr = $this->stringifyConstants($constants);
        $this->phar->addFromString('stubs/constants.php', "<?php\n" . $constantsStr);

        $output->writeln($constantsStr);
    }

    protected function addPackages(OutputInterface $output, bool $iconv) : void
    {
        $finder = (new Finder)
            ->in($this->root . '/vendor')
            ->depth(2)
            ->name('composer.json')
            ->sort(function (SplFileInfo $a, SplFileInfo $b): int
            {
                $aPolyFill = preg_match('/polyfill/', $a->getRelativePath());
                $bPolyFill = preg_match('/polyfill/', $b->getRelativePath());
                if ($aPolyFill && !$bPolyFill)
                {
                    return 1;
                }
                if ($bPolyFill && !$aPolyFill)
                {
                    return -1;
                }
                return strcmp($a->getRelativePath(), $b->getRelativePath());
            });

        $skip = !$iconv ? ['symfony/polyfill-iconv'] : [];
        $rPad = 37;
        $allSkipped = [];

        foreach($finder as $package)
        {
            $name = $package->getRelativePath();
            if (!in_array($name, $skip))
            {
                $output->write(str_pad($name, $rPad));
                $added = $skipped = $bundle = [];
                $countStart = count($this->phar);
                $dir = $this->root . '/vendor/' . $name;

                if (file_exists($license = $dir . '/LICENSE'))
                {
                    $this->addFile($license);
                }
                else
                {
                    $this->addFile($license . '.txt');
                }

                if ($this->bundlePackages && in_array($name, static::BUNDLE_PACKAGES))
                {
                    if (str_starts_with($name, 'psr') || str_starts_with($name, 'paragonie'))
                    {
                        $dir .= '/src';
                    }
                    if ($name == 'phpseclib/phpseclib')
                    {
                        $dir .= '/phpseclib';
                    }
                    $this->handleSpecialCases($name, $dir, $added, $skipped);
                    $classes = ClassOrderer::orderClasses($dir, array_flip(static::BUNDLE_PACKAGES)[$name]);
                    foreach($classes as $ref)
                    {
                        $file = $ref->getFileName();
                        if ($this->acceptFile($name, $file))
                        {
                            $bundle[] = $ref;
                        }
                        else
                        {
                            $skipped[] = substr(realpath($file), strlen($this->root) + 1);
                        }
                    }
                    $this->phar->addFromString('vendor/' . $name . '/bundle.php', $this->mergeFiles($bundle, in_array($name, [
                        'paragonie/constant_time_encoding',
                        'psr/event-dispatcher',
                        'phpseclib/phpseclib'
                    ])));
                    $output->writeln(sprintf(
                        "%d merged, %d invidual, %d skipped",
                        count($bundle),
                        count($this->phar) - $countStart - 1, // -1 because of the bundle
                        count($skipped)
                    ));
                }
                else
                {
                    foreach((new Finder)->files()->in($package->getPath())->name('*.php') as $file)
                    {
                        if ($this->acceptFile($name, (string) $file))
                        {
                            $this->addFile($file);
                        }
                        else
                        {
                            $skipped[] = substr(realpath((string) $file), strlen($this->root) + 1);
                        }
                    }
                    $output->writeln(sprintf(
                        "%d added, %d skipped",
                        count($this->phar) - $countStart,
                        count($skipped)
                    ));
                }
                $allSkipped = array_merge($allSkipped, $skipped);
            }
            else
            {
                $output->writeln(str_pad($name, $rPad) . "skipped");
            }
        }
        $this->phar->addFromString('vendor/skipped', implode("\n", $allSkipped));
    }

    protected function handleSpecialCases(string $package, string $dir, array &$separate, array &$skipped) : void
    {
        // these files need to be at certain paths

        if ($package == 'phpseclib/phpseclib')
        {
            $this->addFile($separate[] = $dir . '/Crypt/Common/AsymmetricKey.php');
            $this->addFile($separate[] = $dir . '/Crypt/EC/PublicKey.php');
            foreach((new Finder)->files()->in([$dir.'/Crypt/*/Formats', $dir.'/Crypt/EC/Curves']) as $file)
            {
                if ($this->acceptFile('phpseclib/phpseclib', (string) $file))
                {
                    $this->addFile($separate[] = $file);
                }
                else
                {
                    $skipped[] = substr(realpath((string) $file), strlen($this->root) + 1);
                }
            }
        }
        elseif ($package == 'symfony/var-dumper')
        {
            $this->addFile($separate[] = $dir . '/Resources/functions/dump.php');
        }
        elseif ($package == 'symfony/string')
        {
            $this->addFile($separate[] = $dir . '/Resources/data/wcswidth_table_wide.php');
            $this->addFile($separate[] = $dir . '/Resources/data/wcswidth_table_zero.php');
            $this->addFile($separate[] = $dir . '/Resources/functions.php');
        }
        elseif($package == 'symfony/cache')
        {
            $this->addFile($separate[] = $dir . '/CacheItem.php');
        }
    }

    protected function acceptFile(string $package, string $file) : bool
    {
        if (preg_match(self::REGEX_LICENSE, $file))
        {
            return true;
        }
        $extensions = ['php'];
        switch($package)
        {
            case 'symfony/cache':
                $skipRegex = '/(Redis|Couchbase|CouchDB|Memcached|Mongo|DynamoDb'
                . '|Zookeeper|Apcu|Pdo|Sql|FirePHP|IFTTT|Elastic'
                . '|Combined|Factory|Traceable|Apcu|Relay|Array|Doctrine'
                . '|DependencyInjection|PhpFiles|Psr16|Chain|Lock'
                . ')/';
                break;
            case 'symfony/http-client':
                $skipRegex = '/(Amp|Caching|Httplug|PrivateNetwork|Retryable'
                . '|Scoping|Throttling|Traceable|Psr18Client'
                . '|NoPrivateNetworkHttpClient|Curl|UriTemplateHttpClient'
                . '|Mock|Test\/'
                . ')/';
                break;
            case 'symfony/http-client-contracts':
                $skipRegex = '/(Test\/)/';
                break;
            case 'symfony/console':
                $skipRegex = '/(Helper\/(Tree|Table))'
                . '|(Command\/(TraceableCommand|LockableTrait|ListCommand|LazyCommand'
                . '|DumpCompletionCommand|CompleteCommand))'
                . '|(Output\/(BufferedOutput|TestOutput))'
                . '|((Messenger|CI|Tester|Debug|DependencyInjection|DataCollector'
                . '|CommandLoader)\/)/';
                break;
            case 'symfony/yaml':
                $skipRegex = '/(Command)/';
                break;
            case 'symfony/process':
                $skipRegex = '/(Windows|Php)/';
                break;
            case 'symfony/var-dumper':
                $skipRegex = '/Server|Html|Test|Command|(Caster\/('
                . 'Imagine|Gd|Gmp|Img|Memca|Mysql|Pdo|Redis|Xml|DOM|Fiber|'
                . 'Amqp|Doctrine|FFI|PgSql|Sqlite|Curl|RdKafka|AddressInfo|'
                . 'OpenSSL|Ds))/';
                break;
            case 'symfony/event-dispatcher':
                $skipRegex = '/(((Debug)\/)|Immutable|Pass\.)/';
                break;
            case 'symfony/var-exporter':
                $skipRegex = '/(Lazy(Proxy|Ghost)Trait)/';
                break;
            case 'symfony/error-handler':
                $skipRegex = '/(html|Html|Test|Command)/';
                break;
            case 'symfony/service-contracts':
                $skipRegex = '/ServiceSubscriberTrait/';
                break;
            case 'symfony/string':
                $skipRegex = '/(French|Spanish)/';
                break;
            case 'symfony/polyfill-php83':
            case 'symfony/polyfill-php85':
                $skipRegex = '/(Resources\/stubs\/(([a-zA-Z]+)(Error|Exception))|Filter)/';
                break;
            case 'phpseclib/phpseclib':
                $skipRegex = '/(phpseclib\/(System|Net|'
                . '(Crypt\/(AES|Rijndael|T|S|Ch|RC|DSA|DH|Salsa|(([a-zA-Z]+)fish)))'
                . ')|JWK|Putty|PuTTY|XML|DES'
                . '|brainpool|sect|(nist(b|k|t))|((sec|nist)p(224|192|160|128|112|256k1))'
                . '|Montgomery|prime|Koblitz|Curve25519|Curve448|Ed448|PSS|MSBLOB|Raw'
                . '|X509|GMP|BCMath|SSH2|IEEE|ANSI|CMS|CRL|CSR|X509|PFX|SPKAC|File\/Common'
                . '|(File\/ASN1\/Maps\/(?!(RSA|EC|Public|Private|AlgorithmIdentifier|Attr'
                . '|SpecifiedE|Field|Curve|One|Other|Hash|Encrypted)))'
                . ')/';
                break;
        }
        if (isset($skipRegex) && preg_match($skipRegex, $file))
        {
            return false;
        }
        return in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions);
    }

    protected function stringifyConstants(array $constants) : string
    {
        $out = [];

        foreach($constants as $k => $v)
        {
            switch(gettype($v))
            {
                case 'boolean':
                    $v = $v ? 'true' : 'false';
                    break;
                case 'int':
                    $v = strval($v);
                    break;
                case 'string':
                    $v = sprintf("'%s'", $v);
                    break;
                case 'NULL':
                    $v = 'null';
                    break;
            }

            $out[] = sprintf(
                "const %s = %s;",
                $k, $v
            );
        }
        $out[] = "define('TARBSD_LICENSE', file_get_contents(__DIR__.'/../LICENSE'));";
        return implode("\n", $out) . "\n";
    }

    protected function addFile(string|SplFileInfo $file) : void
    {
        $file = (string) $file;
        if (isset($this->includedFiles[$realPath = realpath($file)]))
        {
            throw new \LogicException(sprintf(
                "%s already in the archive",
                $realPath
            ));
        }
        if (isset($this->mergedFiles[$realPath]))
        {
            throw new \LogicException(sprintf(
                "%s already in the archive through a bundle",
                $realPath
            ));
        }
        $this->includedFiles[$realPath] = true;
        $this->phar->addFromString(
            substr($realPath, strlen($this->root) + 1),
            $this->readFile($file)
        );
    }

    protected function readFile(string $file) : string
    {
        if (
            $this->minify
            &&
            substr(realpath($file), strlen($this->root) + 1, 6) === 'vendor'
            &&
            pathinfo($file, PATHINFO_EXTENSION) === 'php'
            //&&
            //!preg_match(self::REGEX_ATTRIBUTE, file_get_contents($file))
        ) {
            return php_strip_whitespace($file) . "\n";
        }
        if (pathinfo($file, PATHINFO_EXTENSION) === 'json')
        {
            return json_encode(
                json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        return file_get_contents($file);
    }

    protected function mergeFiles(array $classes, bool $strict) : string
    {
        $out = $strict ? "<?php declare(strict_types=1);\n" :"<?php\n";
        foreach($classes as $ref)
        {
            if (!isset($this->includedFiles[$file = $ref->getFileName()]))
            {
                if (isset($this->mergedFiles[$file]))
                {
                    throw new \LogicException(sprintf(
                        "%s already in the archive",
                        $file
                    ));
                }
                $this->mergedFiles[$file] = true;

                $contents = substr($this->readFile($file), 5);
                if ($strict)
                {
                    $contents = preg_replace('/declare\(strict_types\=1\)\;/', '', $contents);
                }
                $contents = preg_replace(
                    '/namespace\s([a-zA-Z0-9\\\\]+)\;/',
                    'namespace ' . $ref->getNamespaceName() . ' {',
                    $contents
                ) . "}";
                $out .= $contents;
            }
        }
        return $out . "\n";
    }

    protected function genPharStub() : string
    {
        $license = file_get_contents(__DIR__ . '/../LICENSE');
        $stars = str_repeat('*', 72);
        $license = "\n *  " . preg_replace('/\n/', "\n *  ", $license);
        $license = '/' . $stars . $license . "\n " . $stars . '/';
        return sprintf(
            static::STUB,
            $license,
            $this->genStubTests(),
        );
    }

    private function genBootstrap() : string
    {
        $f = file_get_contents($this->root . '/vendor/composer/autoload_static.php');
        if (preg_match('/(ComposerStaticInit[a-z0-9]+)/', $f, $m))
        {
            $initializer = 'Composer\\Autoload\\' . $m[1];
        }
        else
        {
            throw new \Exception('Could not find composer autoload initializer');
        }
        $prefixes = $files = [];
        foreach($initializer::$prefixDirsPsr4 as $ns => $dirs)
        {
            $prefixes[$ns] = [];
            foreach($dirs as $dir)
            {
                $prefixes[$ns][] = '/' . substr(realpath($dir), strlen($this->root) + 1);
            }
        }
        foreach($initializer::$files as $file)
        {
            $files[] = '/' . substr(realpath($file), strlen($this->root) + 1);
        }
        sort($files);

        $classMap = array_map(function(string $file){
            return '/' . substr(realpath($file), strlen($this->root) + 1);
        }, $initializer::$classMap);

        $serializeArr = function(array $arr) : string
        {
            $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
            $str = preg_replace(
                ['/{/', '/}/', '/\"\:/'],
                ['[', ']', '" => '],
                json_encode($arr, $flags)
            );
            return preg_replace('/\n/', "\n    ", $str);
        };

        $bundles = array_map(function(string $bundle) : string
        {
            return '/vendor/' . $bundle;
        }, static::BUNDLE_PACKAGES);
        $bundles = array_merge(['TarBSD' => '/src'], $bundles);

        return sprintf(
            self::BOOTSTRAP,
            $serializeArr($this->bundlePackages ? $bundles : []),
            preg_replace('/\n([\s]{8})/', "\n          __DIR__ . ", $serializeArr($files)),
            preg_replace('/\n([\s]{12})/', "\n            __DIR__ . ", $serializeArr($prefixes)),
            $serializeArr($initializer::$prefixLengthsPsr4),
            preg_replace('/\=\>/', "=> __DIR__ .", $serializeArr($classMap)),
        );
    }

    private function genStubTests() : string
    {
        $tests = [];
        $reqs = Misc::phpRequirements();
        $tests[] = "if (version_compare(PHP_VERSION, '" . $reqs['php'] . "', '<')) \$issues[] = 'PHP >= " . $reqs['php'] . " required, you are running ' . PHP_VERSION;";

        foreach(array_merge(['phar'], $reqs['extensions']) as $ext)
        {
            $tests[] = "if (!extension_loaded('" . $ext . "')) \$issues[] = 'PHP extension " . $ext . " required';";
        }

        if (!$this->phar->hasFile('vendor/symfony/polyfill-iconv/Iconv.php'))
        {
            $tests[] = "if (!extension_loaded('iconv') && !extension_loaded('mbstring')) \$issues[] = 'PHP extension mbstring or iconv required';";
        }

        return implode("\n", $tests);
    }

    const STUB = <<<STUB
#!/usr/bin/env php
<?php
%s
\$issues = [];
if (!defined('TARBSD_TEST'))
{
    if ((\$os = php_uname('s')) !== 'FreeBSD') \$issues[] = 'Unsupported operating system ' . \$os;
}
%s
if (\$issues)
{
    echo "\\n\\ttarBSD builder cannot run due to following issues:\\n\\t\\t" . implode("\\n\\t\\t", \$issues) . "\\n\\n";
    exit(1);
}
Phar::mapPhar('__PHAR__ALIAS__');
\$bootstrap = require 'phar://__PHAR__ALIAS__/bootstrap.php';
if (realpath(\$_SERVER['SCRIPT_FILENAME']) === __FILE__)
{
    return \$bootstrap->run();
}
else
{
    return \$bootstrap;
}
/*****************************************************
 * 
 *  This is a compressed phar archive and thus, not
 *  human-readabale beyond this. If you want to view
 *  the source code, you can extract this archive by
 *  using PHP's phar extension or simply by going to
 *  https://github.com/pavetheway91/tarbsd/
 * 
 *****************************************************/
__HALT_COMPILER(); ?>
STUB;

    const BOOTSTRAP = <<<BOOTSTRAP
<?php
namespace TarBSD;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\ErrorHandler\Debug;
use Composer\Autoload\ClassLoader;
use Closure;

if (!class_exists(ClassLoader::class, false))
{
    require __DIR__ . '/vendor/composer/ClassLoader.php';
}
require __DIR__ . '/vendor/composer/platform_check.php';

return new class() extends ClassLoader
{
    public const __ROOT__ = __DIR__;

    public const __VENDOR__ = __DIR__ . '/vendor';

    const BUNDLES = %s;

    const FILES = %s;

    const PREFIXES = %s;

    const PREFIX_LENGTHS = %s;

    const CLASSMAP = %s;

    public array \$loadedBundles = [];

    protected ?Closure \$loaderCb = null;

    public function __construct()
    {
        parent::__construct(self::__VENDOR__);

        \$init = Closure::bind(function (\$that)
        {
            \$that->prefixLengthsPsr4 = \$that::PREFIX_LENGTHS;
            \$that->prefixDirsPsr4 = \$that::PREFIXES;
            \$that->classMap = \$that::CLASSMAP;
        }, null, ClassLoader::class);

        \$init(\$this);
        \$this->register();

        foreach(self::FILES as \$file)
        {
            if (is_file(\$file))
            {
                require \$file;
            }
        }
    }

    public function run(?InputInterface \$input = null, ?OutputInterface \$output = null) : int
    {
        if (!defined('TARBSD_DEBUG'))
        {
            if (
                (!TARBSD_PORTS && !TARBSD_SELF_UPDATE)
                ||
                (file_exists(\$debug = '/tmp/tarbsd.debug') && filemtime(\$debug) > (time() - 3600))
            ) {
                Debug::enable();
                define('TARBSD_DEBUG', true);
            }
            else
            {
                error_reporting(E_ERROR | E_PARSE);
                ini_set('display_errors', 1);
                define('TARBSD_DEBUG', false);
            }
        }
        return (new App)->run(\$input, \$output);
    }

    public function setLoaderCb(?Closure \$loaderCb = null) : void
    {
        \$this->loaderCb = \$loaderCb;
    }

    public function findFile(\$class)
    {
        if (is_string(\$file = parent::findFile(\$class)))
        {
            if (\$this->loaderCb)
            {
                \$this->loaderCb->__invoke(\$class, \$file);
            }
            return \$file;
        }

        foreach(self::BUNDLES as \$ns => \$dir)
        {
            \$file = __DIR__ . \$dir . '/bundle.php';
            if (!in_array(\$dir, \$this->loadedBundles) && str_starts_with(\$class, \$ns) && is_file(\$file))
            {
                \$this->loadedBundles[] = \$dir;
                if (\$this->loaderCb)
                {
                    \$this->loaderCb->__invoke(\$class, \$ns);
                }
                return \$file;
            }
        }

        if (\$this->loaderCb)
        {
            \$this->loaderCb->__invoke(\$class, false);
        }
    }
};
BOOTSTRAP;
}
