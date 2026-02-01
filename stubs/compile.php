#!/usr/bin/env php
<?php declare(strict_types=1);
namespace TarBSD;
/****************************************************
 * 
 *   This compiles the tarBSD builder executable
 * 
 ****************************************************/
require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use TarBSD\Util\Misc;

use OpenSSLAsymmetricKey;
use Phar;

#[AsCommand(
    name: 'compile',
)]
class Compiler extends Command
{
    private readonly string $root;

    private readonly string $initializer;

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
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('Ports edition without self-update command')] bool $ports = false,
        #[Option('Version tag')] ?string $versionTag = null,
        #[Option('Signature key file')] ?string $key = null,
        #[Option('Signature key password')] ?string $pw = null,
        #[Option('Leave all polyfills out, cuts compile time to ≈ 1/3')] bool $np = false,
        #[Option('Mock Github api')] bool $mockGh = false,
        #[Option('Prefix')] string $prefix = '/usr/local'
    ) {
        $start = time();

        if (ini_get('phar.readonly'))
        {
            throw new \Exception(
                "Please set phar.readonly=0 in /usr/local/etc/php.ini"
            );
        }

        if ($key)
        {
            if ($np)
            {
                throw new \Exception(
                    "Don't build a release without polyfills"
                );
            }

            $key = openssl_pkey_get_private('file://' . $key, $pw);

            if (false == ($key instanceof OpenSSLAsymmetricKey))
            {
                throw new \Exception(
                    "failed to read the signature key"
                );
            }
            $versionTag = $versionTag ?: gmdate('y.m.d');
        }

        if ($ports && !$versionTag)
        {
            throw new \Exception(
                "ports edition needs a version tag"
            );
        }

        $fs = new Filesystem;
        $fs->mkdir($out = dirname(__DIR__) . '/out');
        $fs->remove((new Finder)->in($out));
        $buildDir = $ports ? $out : '/tmp';

        $phar = new Phar(
            $tmpFile = $buildDir . '/tarbsd_' . bin2hex(random_bytes(6)) . '.phar',
            0,
            $id = Misc::genUuid()
        );

        $phar->setStub($this->genStub($id, $ports, $np));

        $debug = true;

        $this->genBootstrap($phar);

        $this->addOwnSrc($output, $phar, $ports, $prefix, $versionTag, $mockGh);

        $this->addPackages($output, $phar, $ports, $np, $debug);

        $phar->compressFiles(Phar::GZ);

        $finalName = $out . '/tarbsd';

        if ($mockGh)
        {
            $finalName .= '_mock';
        }

        if ($key)
        {
            $sigFile = $finalName . '.sig';
            $details = openssl_pkey_get_details($key);
            $sigFile .= '.' . $details['ec']['curve_name'];

            $success = openssl_sign(
                file_get_contents($tmpFile),
                $sig,
                $key
            );

            if (!$success)
            {
                throw new \Exception(
                    "failed to sign the executable"
                );
            }

            $fs->dumpFile(
                $sigFile,
                base64_encode($sig)
            );
        }

        $fs->chmod($tmpFile, 0555);
        $fs->rename($tmpFile, $finalName);

        $output->writeln(sprintf(
            "generated %s\ntime: %s seconds\nid: %s\ntag: %s",
            $finalName,
            time() - $start,
            $id,
            $versionTag
        ));

        return self::SUCCESS;
    }

    protected function stringifyConstants(array $constants) : string
    {
        $out = "const TARBSD_STUBS = __DIR__;\n";

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

            $out .= sprintf(
                "const %s = %s;\n",
                $k, $v
            );
        }
        return $out;
    }

    protected function genStub(string $buildId, bool $ports, bool $np)
    {
        $stub = <<<STUB
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
const TARBSD_BUILD_ID = '%s';
Phar::mapPhar(TARBSD_BUILD_ID);
require 'phar://' . TARBSD_BUILD_ID . '/bootstrap.php';
if (!defined('TARBSD_NO_RUN') || !TARBSD_NO_RUN)
{
    TarBSD\\run();
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
        $license = file_get_contents(__DIR__ . '/../LICENSE');

        $stars = str_repeat('*', 72);
        $license = "\n *  " . preg_replace('/\n/', "\n *  ", $license);
        $license = '/' . $stars . $license . "\n " . $stars . '/';
        $variableTests = '';

        if ($np)
        {
$variableTests = <<<TESTS

if (!extension_loaded('mbstring')) \$issues[] = 'PHP extension mbstring required';
if (!extension_loaded('intl')) \$issues[] = 'PHP extension intl required';
if (!extension_loaded('ctype')) \$issues[] = 'PHP extension ctype required';
TESTS;
        }
        elseif($ports)
        {
$variableTests = <<<NOICONV

if (!extension_loaded('iconv') && !extension_loaded('mbstring')) \$issues[] = 'PHP extension mbstring or iconv required';
NOICONV;
        }

        return sprintf(
            $stub,
            $license,
            $variableTests,
            $buildId
        );
    }

    protected function genBootstrap(Phar $phar)
    {
        $bootstrap = <<<BOOTSTRAP
<?php
namespace TarBSD;
use Composer\Autoload\ClassLoader;
use %s as Initializer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\ErrorHandler\BufferingLogger;

function getClassLoader() : ClassLoader
{
    static \$loader;

    if (null === \$loader)
    {
        if (!class_exists(ClassLoader::class, false))
        {
            require __DIR__ . '/vendor/composer/ClassLoader.php';
        }
        if (!class_exists(Initializer::class, false))
        {
            require __DIR__ . '/vendor/composer/autoload_static.php';
        }

        Initializer::getInitializer(\$loader = new ClassLoader)->__invoke();
        \$loader->register();

        foreach(%s as \$file)
        {
            if (is_file(\$file))
            {
                require \$file;
            }
        }
    }

    return \$loader;
}

function run() : int
{
    \$loader = getClassLoader();

    error_reporting(\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED);

    if (class_exists(ErrorHandler::class))
    {
        ini_set('display_errors', 0);
        @ini_set('zend.assertions', 1);
        ini_set('assert.active', 1);
        ini_set('assert.exception', 1);
        ErrorHandler::register(new ErrorHandler(new BufferingLogger, true));
    }
    else
    {
        ini_set('display_errors', 1);
    }

    \$app = new App(\$loader);
    return \$app->run();
}

BOOTSTRAP;
        $files = [];
        $rootLen = strlen($this->root);

        foreach($this->initializer::$files as $file)
        {
            $file = realpath($file);
            $file = substr($file, $rootLen);
            $files[] = '__DIR__.\'' . $file . '\'';
        }
        $files = "[\n\t\t" . implode(",\n\t\t", $files) . "\n\t]";

        $phar->addFromString('bootstrap.php', sprintf(
            $bootstrap,
            $this->initializer,
            $files
        ));

        foreach([
            'LICENSE',
            'ClassLoader.php',
            'autoload_static.php',
            'installed.php'
        ] as $file) {
            $phar->addFromString(
                'vendor/composer/' . $file,
                file_get_contents($this->root . '/vendor/composer/' . $file)
            );
        }
        return;
    }

    protected function addOwnSrc(
        OutputInterface $output,
        Phar $phar,
        bool $ports,
        string $prefix,
        ?string $versionTag,
        bool $mockGh
    ) {
        $rootLen = strlen($this->root);

        $srcFiles = 0;
        foreach(
            (new Finder)->files()->in($this->root . '/src')
            as $file
        ) {
            $file = (string) $file;
            $phar->addFromString(
                substr($file, $rootLen + 1),
                file_get_contents($file)
            );
            $srcFiles++;
        }

        $constants = [];
        $constants['TARBSD_GITHUB_API'] = $mockGh ? 'http://localhost:8080' : 'https://api.github.com';
        $constants['TARBSD_SELF_UPDATE'] = (!$ports && $versionTag);
        $constants['TARBSD_PORTS'] = $ports;
        $constants['TARBSD_VERSION'] = $versionTag;
        $constants['TARBSD_PREFIX'] = $prefix;
        $constantsStr = $this->stringifyConstants($constants);
        $phar->addFromString('stubs/constants.php', "<?php\n" . $constantsStr);
        $output->write($constantsStr);
        $phar->addFile(__DIR__ . '/../LICENSE', 'LICENSE');

        $stubFiles = 0;
        foreach(
            (new Finder)->files()->in(__DIR__)->depth('0')->notname('*.php')->sortByName()->reverseSorting()
            as $file
        ) {
            $file = (string) $file;
            $phar->addFromString(
                substr($file, $rootLen + 1),
                file_get_contents($file)
            );
            $stubFiles++;
        }

        foreach(
            (new Finder)->directories()->in(__DIR__)->depth('0')
            as $dir
        ) {
            $dir = (string) $dir;
            foreach((new Finder)->in($dir) as $file)
            {
                if ($file->isFile())
                {
                    $file = (string) $file;
                    $relativeFile = substr($file, $rootLen + 1);
                    $phar->addFile($file, $relativeFile);
                    $stubFiles++;
                }
            }
        }

        $output->writeln(sprintf(
            "%d src files\n%d stub files",
            $srcFiles,
            $stubFiles
        ));
    }

    protected function addPackages(OutputInterface $output, Phar $phar, bool $ports, bool $np, bool $debug)
    {
        $rootLen = strlen($this->root);

        $find = function(Phar $phar) use ($rootLen)
        {
            $f = (new Finder)
                ->directories()
                ->in($this->root . '/vendor')
                ->depth(1);

            foreach($f as $package)
            {
                $name = $package->getRelativePathName();
                $path = $this->root . '/vendor/' . $name . '/';

                if (!file_exists($path . '/composer.json'))
                {
                    continue;
                }

                yield $name => function() use ($phar, $name, $path, $rootLen)
                {
                    $foundLicenseFile = null;

                    foreach(['LICENSE.txt', 'LICENSE'] as $licenseFile)
                    {
                        if (file_exists($licenseFile = $path . $licenseFile))
                        {
                            $foundLicenseFile = $licenseFile;
                        }
                    }

                    if (!$foundLicenseFile)
                    {
                        throw new \Exception('Cannot find license file for ' . $name);
                    }

                    $phar->addFromString(
                        substr($foundLicenseFile, $rootLen + 1),
                        file_get_contents($foundLicenseFile)
                    );

                    $added = [];
                    $skipped = [];

                    foreach(
                        (new Finder)->files()->in($path)
                        as $file
                    ) {
                        $file = (string) $file;
                        $relativeFile = substr($file, $rootLen + 1);

                        if ($this->accept($name, $relativeFile))
                        {
                            $phar->addFromString(
                                $relativeFile,
                                pathinfo($file, PATHINFO_EXTENSION) == 'php'
                                    ? php_strip_whitespace($file)
                                    : file_get_contents($file)
                            );
                            $added[] = $relativeFile;
                        }
                        else
                        {
                            $skipped[] = $relativeFile;
                        }
                    }
                    return [$added, $skipped];
                };
            }
        };

        $allAdded = $allSkipped = [];
        $packages = 0;
        foreach($find($phar) as $package => $cb)
        {
            if (
                ($package == 'symfony/polyfill-iconv' && $ports)
                || (preg_match(
                    '/^symfony\/polyfill-(iconv|mbstring|ctype|intl)/',
                    $package
                ) && $np)
                || (preg_match(
                    '/^symfony\/(var-dumper|error-handler)/',
                    $package
                ) && !$debug)
            ) {
                $output->write("skipping " . $package . "\n");
            }
            else
            {
                $output->write("    adding files for " . $package . ' ');
                [$added, $skipped] = $cb();
                $output->writeln(sprintf(
                    "%d added, %d skipped",
                    count($added),
                    count($skipped)
                ));
                $allAdded = array_merge($allAdded, $added);
                $allSkipped = array_merge($allSkipped, $skipped);
            }
            $packages++;
        }

        $phar->addFromString(
            'vendor/files.skipped',
            implode("\n", array_filter($allSkipped, function(string $v)
            {
                return str_ends_with($v, '.php');
            }))
        );

        $output->writeln(sprintf(
            "    %d files added, %d skipped across %s packages",
            count($allAdded),
            count($allSkipped),
            $packages
        ));
    }

    protected function accept(string $package, string $file) : bool
    {
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
            case 'symfony/console':
                $skipRegex = '/(Helper\/(Tree|Table))/';
                $extensions = array_merge($extensions, [
                    'bash', 'zsh', 'fish'
                ]);
                break;
            case 'symfony/yaml':
                $skipRegex = '/(Command)/';
                break;
            case 'symfony/polyfill-uuid':
                $skipRegex = '/(bootstrap)/';
                break;
        }
        if (isset($skipRegex) && preg_match($skipRegex, $file))
        {
            return false;
        }
        return in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions);
    }
}

$app = new Application;
$app->addCommand(new Compiler);
$app->setDefaultCommand('compile', true);
$app->run();