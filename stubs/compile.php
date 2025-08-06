#!/usr/bin/env php
<?php
namespace TarBSD;
/****************************************************
 * 
 *   This compiles the tarBSD builder executable
 * 
 ****************************************************/
require __DIR__ . '/../vendor/autoload.php';
use TarBSD\App;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use OpenSSLAsymmetricKey;
use Phar;

#[AsCommand(
    name: 'compile',
)]
class Compiler extends Command
{
    private readonly string $root;

    public function __construct()
    {
        parent::__construct();
        $this->root = dirname(__DIR__);
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('Ports edition without self-update command')] bool $ports = false,
        #[Option('Signature key file')] ?string $key = null,
        #[Option('Signature key password')] ?string $pw = null,
        #[Option('Mock Github api')] bool $mockGh = false
    ) {
        if (ini_get('phar.readonly'))
        {
            throw new \Exception(
                "Please set phar.readonly=0 in /usr/local/etc/php.ini"
            );
        }

        if ($key)
        {
            $key = openssl_pkey_get_private('file://' . $key, $pw);
            if (false == ($key instanceof OpenSSLAsymmetricKey))
            {
                throw new \Exception(
                    "failed to read the signature key"
                );
            }
        }

        $fs = new Filesystem;

        $phar = new Phar(
            $tmpFile = '/tmp/tarbsd_' . bin2hex(random_bytes(6)) . '.phar',
            0,
            $id = uuid_create(UUID_TYPE_TIME)
        );

        $phar->setStub($this->genStub($id));

        $rootlen = strlen($this->root);

        foreach(
            (new Finder)->files()->in($this->root . '/src')
            as $file
        ) {
            $phar->addFromString(
                substr($file, $rootlen + 1),
                file_get_contents($file)
            );
        }

        $constants = [];
        $constants['TARBSD_GITHUB_API'] = $mockGh ? 'http://localhost:8080' : 'https://api.github.com';
        $constants['TARBSD_SELF_UPDATE'] = ($ports || !$key) ? false : true;
        $constants['TARBSD_PORTS'] = $ports;

        $constantsStr = "<?php\nconst TARBSD_STUBS = __DIR__;\n";

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
            }

            $constantsStr .= sprintf(
                "const %s = %s;\n",
                $k, $v
            );
        }

        $phar->addFromString('stubs/constants.php', $constantsStr);

        if ($key)
        {
            $phar->addFromString('stubs/isRelease', '');
        }

        $phar->addFile(__DIR__ . '/../LICENSE', 'LICENSE');

        foreach(
            (new Finder)->files()->in(__DIR__)->depth('0')->notname('*.php')->sortByName()->reverseSorting()
            as $file
        ) {
            $phar->addFromString(
                substr($file, $rootlen + 1),
                file_get_contents($file)
            );
        }
        foreach(
            (new Finder)->directories()->in(__DIR__)->depth('0')
            as $dir
        ) {
            foreach((new Finder)->in($dir) as $file)
            {
                $relativeFile = substr($file, $rootlen + 1);
                if ($file->isFile())
                {
                    $phar->addFile($file, $relativeFile);
                }
                else
                {
                    $phar->addEmptyDir($relativeFile);
                }
            }
        }

        $this->addAutoloaderFiles($phar);

        foreach($this->addPackages($phar) as $package => $cb)
        {
            $output->write("adding files for " . $package . ' ');
            [$added, $skipped] = $cb();
            $output->writeln(sprintf(
                "%d added, %d skipped",
                count($added),
                count($skipped)
            ));
        }

        $phar->compressFiles(Phar::GZ);

        $fs->chmod($tmpFile, 0770);
        $fs->mkdir($out = dirname(__DIR__) . '/out');
        $fs->remove((new Finder)->in($out));

        $finalName = $phar = $out . '/tarbsd';

        if ($mockGh)
        {
            $finalName .= '_mock';
        }

        if ($key)
        {
            $sigFile = $finalName . '.sig';

            $details = openssl_pkey_get_details($key);

            if (isset($details['ec']))
            {
                $sigFile .= '.' . $details['ec']['curve_name'];
            }
            elseif (isset($details['rsa']))
            {
                $sigFile .= '.rsa';
            }

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

            Process::fromShellCommandline(
                "tar -v --zstd --options zstd:compression-level=19 -cf out/src-with-dependencies.tar "
                . "src stubs vendor LICENSE composer.json composer.lock",
                $this->root,
            )->mustRun(function ($type, $buffer)
            {
            });
        }

        $fs->chmod($tmpFile, 0555);
        $fs->rename($tmpFile, $finalName);
        $fs->dumpFile(
            $finalName . '.sig.sha256',
            hash_file('sha256', $finalName)
        );

        $output->writeln('generated ' . $finalName);
    }

    protected function genStub(string $buildId)
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
if (!extension_loaded('pcntl')) \$issues[] = 'PHP extension pcntl required';
if (!extension_loaded('mbstring') && !extension_loaded('iconv')) \$issues[] = 'Eighter PHP extension mbstring or iconv required';
if (\$issues) exit("\\n\\ttarBSD builder cannot run due to following issues:\\n\\t\\t" . implode("\\n\\t\\t", \$issues) . "\\n\\n");
const TARBSD_BUILD_ID = '%s';
Phar::mapPhar(TARBSD_BUILD_ID);
require 'phar://' . TARBSD_BUILD_ID . '/vendor/autoload.php';
(new TarBSD\App)->run(
    new Symfony\Component\Console\Input\ArgvInput,
    new Symfony\Component\Console\Output\ConsoleOutput
);
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

        return sprintf($stub, $license, $buildId);
    }

    protected function addAutoloaderFiles(Phar $phar)
    {
        $rootlen = strlen($this->root);

        foreach(['composer/LICENSE', 'autoload.php'] as $file)
        {
            $phar->addFromString(
                'vendor/' . $file,
                file_get_contents($this->root . '/vendor/' . $file)
            );
        }

        $f = (new Finder)
            ->files()
            ->in([$this->root . '/vendor/composer'])
            ->depth(0)
            ->name('*.php');

        foreach($f as $file)
        {
            $phar->addFromString(
                substr($file, $rootlen + 1),
                php_strip_whitespace($file)
            );
        }
    }

    protected function addPackages(Phar $phar)
    {
        $rootlen = strlen($this->root);

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

            yield $name => function() use ($phar, $name, $path, $rootlen)
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
                    substr($foundLicenseFile, $rootlen + 1),
                    file_get_contents($foundLicenseFile)
                );

                $added = [];
                $skipped = [];

                foreach(
                    (new Finder)->files()->in($path)
                    as $file
                ) {
                    $relativeFile = substr($file, $rootlen + 1);

                    if ($this->accept($name, $relativeFile))
                    {
                        $phar->addFromString(
                            $relativeFile,
                            $file->getExtension() == 'php'
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
    }

    protected function accept(string $package, string $file) : bool
    {
        $extensions = ['php'];

        switch($package)
        {
            case 'symfony/cache':
                if (preg_match(
                    '/('
                        . 'Redis|Couchbase|CouchDB|Memcached|Mongo|DynamoDb'
                        . '|Zookeeper|Apcu|Pdo|Sql|FirePHP|IFTTT|Elastic'
                        . '|Combined|Factory|Traceable|Apcu'
                    . ')/',
                    $file
                )) {
                    return false;
                }
                break;
            case 'symfony/console':
                $extensions = array_merge($extensions, [
                    'bash', 'zsh', 'fish'
                ]);
                break;
            case 'symfony/yaml':
                if (preg_match(
                    '/(Command|Ulid)/',
                    $file
                )) {
                    return false;
                }
                break;
        }
        if (!in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions))
        {
            return false;
        }
        return true;
    }
}

$app = new Application;
$app->add(new Compiler);
$app->setDefaultCommand('compile', true);
$app->run();