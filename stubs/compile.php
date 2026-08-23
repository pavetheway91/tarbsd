#!/usr/bin/env php
<?php declare(strict_types=1);
namespace TarBSD\Compile;
/****************************************************
 * 
 *   This compiles the tarBSD builder executable
 * 
 ****************************************************/
require __DIR__ . '/AbstractCompiler.php';

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Application;

use OpenSSLAsymmetricKey;

#[AsCommand(name: 'dev')]
class DevCompiler extends AbstractCompiler
{
    public function __invoke(
        OutputInterface $output,
        #[Option('Compress')] bool $compress = true,
        #[Option('Minify')] bool $minify = true,
        #[Option('Iconv polyfill')] bool $iconv = true,
        #[Option('Bundle Packages', 'bundle')] bool $bundlePackages = true,
        #[Option('Prefix', '', 'p')] string $prefix = '/usr/local'
    ) : int {
        $start = time();
        $this->phar->compress = $compress;
        $this->minify = $minify;
        $this->bundlePackages = $bundlePackages;

        $versionTag = 'dev-' . gmdate('y.m.d H:i:s');

        $this->addBootsraptFiles();
        $this->addOwnSrc($output, false, $prefix, $versionTag, false);
        $this->addPackages($output, $iconv);
        $this->write($output, $start, $versionTag);
        return self::SUCCESS;
    }
}

#[AsCommand(name: 'port')]
class PortCompiler extends AbstractCompiler
{
    public function __invoke(
        OutputInterface $output,
        #[Option('Version tag', '', 't')] ?string $versionTag = null,
        #[Option('Prefix', '', 'p')] string $prefix = '/usr/local',
    ) : int {
        $start = time();
        $this->phar->compress = true;
        $this->minify = true;
        $this->bundlePackages = true;

        $this->addBootsraptFiles();
        $this->addOwnSrc($output, true, $prefix, $versionTag, true);
        $this->addPackages($output, false);
        $this->write($output, $start, $versionTag);

        return self::SUCCESS;
    }
}

#[AsCommand(name: 'github')]
class GitHubCompiler extends AbstractCompiler
{
    public function __invoke(
        OutputInterface $output,
        #[Option('Signature key file', '', 'k')] ?string $key = null,
        #[Option('Signature key password', '', 'pw')] ?string $pw = null,
        #[Option('Version tag', '', 't')] ?string $versionTag = null
    ) : int {
        $start = time();
        $this->phar->compress = true;
        $this->minify = true;
        $this->bundlePackages = true;

        if (!file_exists($key))
        {
            throw new \Exception("key file does not exist");
        }
        $key = openssl_pkey_get_private(file_get_contents($key), $pw);
        if (false == ($key instanceof OpenSSLAsymmetricKey))
        {
            throw new \Exception("failed to read the signature key");
        }

        $versionTag = $versionTag ?: gmdate('y.m.d');

        $this->addBootsraptFiles();
        $this->addOwnSrc($output, false, '/usr/local', $versionTag, true);
        $this->addPackages($output, true);
        $phar = $this->write($output, $start, $versionTag, $key);

        $sigFile = $phar . '.sig';
        $details = openssl_pkey_get_details($key);
        $sigFile .= '.' . $details['ec']['curve_name'];

        if (!openssl_sign(file_get_contents($phar), $sig, $key))
        {
            throw new \Exception("failed to sign the executable");
        }

        file_put_contents($sigFile, base64_encode($sig));

        return self::SUCCESS;
    }
}

$app = new Application;
$app->addCommand(new DevCompiler);
$app->addCommand(new PortCompiler);
$app->addCommand(new GitHubCompiler);
$app->setDefaultCommand('dev');
$app->run();
