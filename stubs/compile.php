#!/usr/bin/env php
<?php declare(strict_types=1);
namespace TarBSD;
/****************************************************
 * 
 *   This compiles the tarBSD builder executable
 * 
 ****************************************************/
require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Cache\Adapter\FilesystemAdapter as FilesystemCache;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

use OpenSSLAsymmetricKey;
use SplFileInfo;
use Phar;

#[AsCommand(
    name: 'compile',
)]
class Compiler extends Command
{
    const REGEX_LICENSE = '{(license|copying|copyright)(\.[a-z]{2,3}|)$}Di';

    const REGEX_ATTRIBUTE = '{(\#\[(\\\\|)([a-z0-9_]+)(\]|\())}Di';

    private const SIG = 0x00010000;

    private const PHAR_ENT_PERM_MASK = 0x000001FF;

    private int $flags = self::SIG;

    private readonly string $root;

    private readonly string $initializer;

    private bool $compress;

    private bool $minify;

    private bool $zopfli = false;

    private array $files = [];

    public function __construct()
    {
        parent::__construct();
        $this->root = dirname(__DIR__);

        $f = file_get_contents($this->root . '/vendor/composer/autoload_static.php');
        if (preg_match('/(ComposerStaticInit[a-z0-9]+)/', $f, $m))
        {
            $this->initializer = 'Composer\\Autoload\\' . $m[1];
        }
        else
        {
            throw new \Exception('Could not find composer autoload initializer');
        }

        try
        {
            Process::fromShellCommandline('zopfli -h')->mustRun();
            $this->zopfli = true;
        }
        catch (\Exception $e)
        {}
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('Ports edition without self-update command')] bool $ports = false,
        #[Option('Version tag')] ?string $versionTag = null,
        #[Option('Signature key file')] ?string $key = null,
        #[Option('Signature key password')] ?string $pw = null,
        #[Option('Prefix')] string $prefix = '/usr/local',
        #[Option('Compress')] bool $compress = true,
        #[Option('Minify')] bool $minify = true
    ) {
        $start = time();

        if ($key)
        {
            $key = openssl_pkey_get_private('file://' . $key, $pw);
            if (false == ($key instanceof OpenSSLAsymmetricKey))
            {
                throw new \Exception(
                    "failed to read the signature key"
                );
            }
            $versionTag = $versionTag ?: gmdate('y.m.d');
        }

        if (($ports || $key) && (!$compress || !$minify))
        {
            throw new \Exception;
        }

        $this->compress = $compress;
        $this->minify = $minify;

        if ($ports && !$versionTag)
        {
            throw new \Exception(
                "ports edition needs a version tag"
            );
        }

        $this->genBootstrap();
        $this->addOwnSrc($output, $ports, $prefix, $versionTag, $production = $ports || $key);
        $this->addPackages($output, $ports, $production);

        $fs = new Filesystem;
        $fs->mkdir($out = dirname(__DIR__) . '/out');
        $fs->remove((new Finder)->in($out));

        $alias = $this->save($finalFile = $out . '/tarbsd');

        if ($key)
        {
            $sigFile = $finalFile . '.sig';
            $details = openssl_pkey_get_details($key);
            $sigFile .= '.' . $details['ec']['curve_name'];

            if (!openssl_sign(file_get_contents($finalFile), $sig, $key))
            {
                throw new \Exception(
                    "failed to sign the executable"
                );
            }

            $fs->dumpFile($sigFile, base64_encode($sig));
        }

        $size = $compressedSize = $numFiles = 0;

        foreach($this->files as $file)
        {
            $compressedSize = $compressedSize + $file->compressedSize;
            $size = $size + $file->origSize;
            $numFiles++;
        }

        $output->writeln(sprintf(
            "generated %s\ntime: %s seconds\ntag: %s\nalias: %s\nfiles: %s\ncompress ratio: %.2fX, %0.1f%%\nzopfli: %s",
            $finalFile,
            time() - $start,
            $versionTag,
            $alias,
            $numFiles,
            $size / $compressedSize,
            $compressedSize / $size * 100,
            $this->zopfli ? 'yep' : 'nope'
        ));

        return self::SUCCESS;
    }

    protected function genBootstrap()
    {
        [$files, $prefixes, $lengths, $classMap] = $this->genAutoload(true);
        $this->addFromString('bootstrap.php', sprintf(
            self::BOOTSTRAP,
            $files, $prefixes, $lengths, $classMap
        ));
        $this->addFile(__DIR__ . '/../vendor/composer/LICENSE');
        $this->addFile(__DIR__ . '/../vendor/composer/ClassLoader.php');
        $this->addFile(__DIR__ . '/../vendor/composer/InstalledVersions.php');
        $this->addFile(__DIR__ . '/../vendor/composer/installed.php');
    }

    protected function addOwnSrc(
        OutputInterface $output,
        bool $ports,
        string $prefix,
        ?string $versionTag,
        bool $production
    ) {
        $this->addFile(__DIR__ . '/../LICENSE');

        $srcFiles = 0;
        $output->write("adding files for src ");
        foreach(
            (new Finder)->files()->files()->in($this->root . '/src')
            as $file
        ) {
            $this->addFile($file);
            $srcFiles++;
        }
        $output->writeln(sprintf("%d files", $srcFiles));

        $constants = [];
        $constants['TARBSD_GITHUB_API'] = 'https://api.github.com';
        $constants['TARBSD_SELF_UPDATE'] = (!$ports && $production);
        $constants['TARBSD_PORTS'] = $ports;
        $constants['TARBSD_VERSION'] = $versionTag;
        $constants['TARBSD_PREFIX'] = $prefix;
        $constantsStr = $this->stringifyConstants($constants);
        $this->addFromString('stubs/constants.php', "<?php\n" . $constantsStr);

        $stubFiles = 0;
        $output->write("adding files for stubs ");
        foreach(
            (new Finder)->files()->in(__DIR__)->depth('0')->notname('*.php')->sortByName()->reverseSorting()
            as $file
        ) {
            $this->addFile($file);
            $stubFiles++;
        }
        foreach(
            (new Finder)->directories()->in(__DIR__)->depth('0')
            as $dir
        ) {
            $dir = (string) $dir;
            foreach((new Finder)->files()->in($dir) as $file)
            {
                if ($file->isFile())
                {
                    $this->addFile($file);
                    $stubFiles++;
                }
            }
        }
        $output->writeln(sprintf("%d files", $stubFiles));
        $output->writeln($constantsStr);
    }

    protected function addPackages(
        OutputInterface $output,
        bool $ports,
        bool $production
    ) {
        $allSkipped = [];

        $finder = (new Finder)
            ->in($this->root . '/vendor')
            ->depth(2)
            ->name('composer.json');

        $skip = $ports ? ['symfony/polyfill-iconv'] : [];

        foreach($finder as $package)
        {
            $name = $package->getRelativePath();
            if (!in_array($name, $skip))
            {
                $output->write("adding files for " . $name . ' ');
                $added = $skipped = [];

                $finder = (new Finder)->files()->in($package->getPath())
                    ->sort(function (SplFileInfo $a, SplFileInfo $b): int {
                        if (preg_match(self::REGEX_LICENSE, $b = $b->getFileName()))
                        {
                            return 1;
                        }
                        if (preg_match(self::REGEX_LICENSE, $a = $a->getFileName()))
                        {
                            return -1;
                        }
                        return strcmp($a, $b);
                    }
                );

                foreach($finder as $file)
                {
                    if ($this->acceptFile($name, (string) $file))
                    {
                        $this->addFile($file);
                        $added[] = $file;
                    }
                    else
                    {
                        $skipped[] = substr(realpath((string) $file), strlen($this->root) + 1);
                    }
                }
                $output->writeln(sprintf(
                    "%d added, %d skipped",
                    count($added),
                    count($skipped)
                ));
                $allSkipped = array_merge($allSkipped, $skipped);
            }
            else
            {
                $output->write("skipping " . $name . "\n");
            }
        }
        $this->addFromString('vendor/skipped', implode("\n", $allSkipped));
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
                $skipRegex = '/('
                        . 'Redis|Couchbase|CouchDB|Memcached|Mongo|DynamoDb'
                        . '|Zookeeper|Apcu|Pdo|Sql|FirePHP|IFTTT|Elastic'
                        . '|Combined|Factory|Traceable|Apcu|Relay|Array|Doctrine'
                    . ')/';
                break;
            case 'symfony/http-client':
                $skipRegex = '/('
                        . 'Amp|Caching|Httplug|PrivateNetwork|Retryable'
                        . '|Scoping|Throttling|Traceable|Psr18Client'
                        . '|NoPrivateNetworkHttpClient|Curl'
                    . ')/';
                break;
            case 'symfony/http-client-contracts':
                $skipRegex = '/(Test\/)/';
                break;
            case 'symfony/console':
                $skipRegex = '/(Helper\/(Tree|Table))/';
                $extensions = array_merge($extensions, [
                    'bash', 'zsh', 'fish'
                ]);
                break;
            case 'symfony/yaml':
                $skipRegex = '/(Command)/';
                break;
            case 'symfony/process':
                $skipRegex = '/(Windows|Php)/';
                break;
            case 'symfony/var-dumper':
                $skipRegex = '/(Server|Html|Test|Command|(Caster\/('
                . 'Imagine|Gd|Gmp|Img|Memca|Mysql|Pdo|Redis|Xml|Dom|'
                . 'Amqp|Doctrine|FFI|PgSql|Sqlite|Curl)))/';
                break;
            case 'symfony/error-handler':
                $skipRegex = '/(html|Html|Test|Command)/';
                break;
            case 'phpseclib/phpseclib':
                $skipRegex = '/(phpseclib\/(System|Net|'
                    . '(Crypt\/(AES|Rijndael|T|S|Ch|RC|DSA|DH|Salsa|(([a-zA-Z]+)fish)))'
                . ')|JWK|Putty|DES|brainpool|prime|sect|nist[^p])/';
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
        $out = ["const TARBSD_STUBS = __DIR__;"];

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
        return implode("\n",$out);
    }

    protected function genAutoload(bool $pretty = true) : array
    {
        $prefixes = $files = [];
        foreach($this->initializer::$prefixDirsPsr4 as $ns => $dirs)
        {
            $prefixes[$ns] = [];
            foreach($dirs as $dir)
            {
                $prefixes[$ns][] = '/' . substr(realpath($dir), strlen($this->root) + 1);
            }
        }
        foreach($this->initializer::$files as $file)
        {
            $files[] = '/' . substr(realpath($file), strlen($this->root) + 1);
        }
        sort($files);

        $classMap = array_map(function(string $file){
            return '/' . substr(realpath($file), strlen($this->root) + 1);
        }, $this->initializer::$classMap);

        $serializeArr = function(array $arr) use ($pretty) : string
        {
            $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;
            if ($pretty)
            {
                $flags |= JSON_PRETTY_PRINT;
            }
            $str = preg_replace(
                ['/{/', '/}/', '/\"\:/'],
                ['[', ']', '" => '],
                json_encode($arr, $flags)
            );
            return preg_replace('/\n/', "\n    ", $str);
        };

        return [
            preg_replace('/\n([\s]{8})/', "\n          __DIR__ . ", $serializeArr($files)),
            preg_replace('/\n([\s]{12})/', "\n            __DIR__ . ", $serializeArr($prefixes)),
            $serializeArr($this->initializer::$prefixLengthsPsr4),
            preg_replace('/\=\>/', "=> __DIR__ .", $serializeArr($classMap)),
        ];
    }

    protected function addFile(string|SplFileInfo $file) : void
    {
        $file = (string) $file;
        $this->addFromString(
            substr(realpath($file), strlen($this->root) + 1),
            $this->readFile($file)
        );
    }

    protected function addFromString(string $path, string $contents)
    {
        $this->files[$path] = $this->processFile(
            $path, $contents,
            0555, 0
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
            &&
            !preg_match(self::REGEX_ATTRIBUTE, file_get_contents($file))
        ) {
            return php_strip_whitespace($file);
        }
        return file_get_contents($file);
    }

    protected function genStub() : string
    {
        $license = file_get_contents(__DIR__ . '/../LICENSE');

        $stars = str_repeat('*', 72);
        $license = "\n *  " . preg_replace('/\n/', "\n *  ", $license);
        $license = '/' . $stars . $license . "\n " . $stars . '/';
        $extratest = '';
        if (!isset($this->files['vendor/symfony/polyfill-iconv/Iconv.php']))
        {
            $extratest = <<<TEST

if (!extension_loaded('iconv') && !extension_loaded('mbstring')) \$issues[] = 'PHP extension mbstring or iconv required';
TEST;
        }
        return sprintf(
            self::STUB,
            $license,
            $extratest
        );
    }

    protected function save(string $file, ?string $alias = null) : string
    {
        $handle = fopen($tmp = sys_get_temp_dir() . bin2hex(random_bytes(8)), 'wb');

        $hash = sha1(serialize(array_map(function($file)
        {
            return $file->toArray();
        }, $this->files)));

        $alias = $alias ?: $this->generateAlias($hash);

        fwrite($handle, preg_replace('/__PHAR__ALIAS__/', $alias, $this->genStub()) . "\n");

        $manifest = $this->serializeManifest($this->files, $alias, $this->flags);

        fwrite($handle, $manifest);

        foreach($this->files as $pharFile)
        {
            fwrite($handle, $pharFile->contents);
        }

        fwrite($handle, hash_file('sha256', $tmp, true) . pack('V', Phar::SHA256));
        fwrite($handle, 'GBMB');
        fclose($handle);

        @unlink($file);
        rename($tmp, $file);
        chmod($file, 0555);
        return $alias;
    }

    protected function processFile(string $path, string $contents, int $perms, int $time) : object
    {
        $origSize = strlen($contents);
        $crc32 = crc32($contents);

        $flags = $perms & self::PHAR_ENT_PERM_MASK;

        if ($origSize > 20 && $this->compress)
        {
            $contents = $this->deflate($contents);
            $flags |= Phar::GZ;
            $this->flags |= Phar::GZ;
        }

        return new class(
            $contents, $time, $origSize,
            strlen($contents), $crc32, $flags
        ) {
            public function __construct(
                public readonly string $contents,
                public readonly int $time,
                public readonly int $origSize,
                public readonly int $compressedSize,
                public readonly int $crc32,
                public readonly int $flags
            ) {}

            public function toArray() : array
            {
                return [
                    $this->contents, $this->time, $this->origSize,
                    $this->compressedSize, $this->crc32, $this->flags
                ];
            }
        };
    }

    protected function deflate(string $payload) : string
    {
        static $cache;
        @mkdir($cacheDir = dirname(__DIR__) . '/.cache');
        $cache = $cache ?: new FilesystemCache('', 0, $cacheDir);
        if ($this->zopfli)
        {
            $item = $cache->getItem(hash_hmac('sha256', $payload, 'zopfli'));
            if (!$item->isHit())
            {
                $tmp = sys_get_temp_dir() . bin2hex(random_bytes(8));
                file_put_contents($tmp, $payload);
                $out = Process::fromShellCommandline(
                    sprintf("zopfli -c --deflate %s", $tmp)
                )->mustRun()->getOutput();
                unlink($tmp);
            }
            else
            {
                return $item->get();
            }
        }
        else
        {
            $item = $cache->getItem(hash_hmac('sha256', $payload, 'zlib'));
            if (!$item->isHit())
            {
                $out = gzdeflate($payload, 9);
            }
            else
            {
                return $item->get();
            }
        }
        $item->set($out)->expiresAfter(
            random_int(7776000, 7776000 * 2) // 90-180 days
        );
        $cache->save($item);
        return $out;
    }

    protected function serializeManifest(array $files, string $alias, int $flags) : string
    {
        $compressed = (($flags & Phar::GZ) === Phar::GZ)
                    || (($flags & Phar::BZ2) === Phar::BZ2);

        $apiver = [1, 1, 1];

        $ret = chr(($apiver[0] << 4) + $apiver[1])
            . chr(($apiver[2] << 4) + ($compressed ? 0x1 : 0));

        $ret .= pack('V', $flags);
        $ret .= pack('V', strlen($alias)) . $alias;
        //$metadata = serialize(null);
        $ret .= pack('V', 0);// . $metadata;

        foreach ($files as $path => $file)
        {
            $ret .= pack('V', strlen($path)) . $path;
            $ret .= pack('VVVVV',
                        $file->origSize, $file->time, $file->compressedSize,
                        $file->crc32, $file->flags
                    );
            //$metadata = serialize(null);
            $ret .= pack('V', 0);// . $metadata;
        }
        return pack('VV', strlen($ret) + 4, count($files)) . $ret;
    }

    protected function generateAlias(string $hash) : string
    {
        $uuid = substr($hash, 0, 16);
        $uuid[8] = $uuid[8] & "\x3F" | "\x80";
        $uuid = substr_replace(bin2hex($uuid), '-', 8, 0);
        $uuid = substr_replace($uuid, '-8', 13, 1);
        $uuid = substr_replace($uuid, '-', 18, 0);
        return substr_replace($uuid, '-', 23, 0);
    }

    private const STUB = <<<STUB
#!/usr/bin/env php
<?php
%s
\$issues = [];
if ((\$os = php_uname('s')) !== 'FreeBSD') \$issues[] = 'Unsupported operating system ' . \$os;
if (!(PHP_VERSION_ID >= 80200)) \$issues[] = 'PHP >= 8.2.0 required, you are running ' . PHP_VERSION;
if (!extension_loaded('phar')) \$issues[] = 'PHP extension phar required';
if (!extension_loaded('zlib')) \$issues[] = 'PHP extension zlib required';
if (!extension_loaded('openssl')) \$issues[] = 'PHP extension openssl required';
if (!extension_loaded('pcntl')) \$issues[] = 'PHP extension pcntl required';
if (!extension_loaded('filter')) \$issues[] = 'PHP extension filter required';%s
if (\$issues)
{
    echo "\\n\\ttarBSD builder cannot run due to following issues:\\n\\t\\t" . implode("\\n\\t\\t", \$issues) . "\\n\\n";
    exit(1);
}
const TARBSD_BUILD_ID = '__PHAR__ALIAS__';
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

    private const BOOTSTRAP = <<<BOOTSTRAP
<?php
namespace TarBSD;

use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\Finder\Finder;
use Composer\Autoload\ClassLoader;
use Closure;

if (!class_exists(ClassLoader::class, false))
{
    require __DIR__ . '/vendor/composer/ClassLoader.php';
}

return new class() extends ClassLoader
{
    public const __ROOT__ = __DIR__;

    public const __VENDOR__ = __DIR__ . '/vendor';

    const FILES = %s;

    const PREFIXES = %s;

    const PREFIX_LENGTHS = %s;

    const CLASSMAP = %s;

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

    public function run() : int
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

        return (new App(\$this))->run();
    }

    public function loadAllClasses() : void
    {
        \$include = function(string \$file) : void
        {
            if (!in_array(\$file, get_included_files()))
            {
                set_error_handler(function(int \$errno, string \$errstr, string \$errfile, int \$errline){});

                try
                {
                    include \$file;
                }
                catch (\Throwable \$e) {}

                restore_error_handler();
            }
        };

        foreach(\$this->getPrefixesPsr4() as \$ns => \$dirs)
        {
            foreach((new Finder)->files()->in(\$dirs)->name('*.php') as \$file)
            {
                if (!preg_match('/Resources/', \$file->getRelativePathName()))
                {
                    \$include((string) \$file);
                }
            }
        }

        foreach(\$this->getClassMap() as \$class => \$file)
        {
            if (
                !class_exists(\$class, false)
                && !interface_exists(\$class, false)
                && !trait_exists(\$class, false)
                && !enum_exists(\$class, false)
            ) {
                \$include(\$file);
            }
        }
    }
};
BOOTSTRAP;
}

$app = new Application;
$app->addCommand(new Compiler);
$app->setDefaultCommand('compile', true);
$app->run();