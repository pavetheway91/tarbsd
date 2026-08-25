<?php declare(strict_types=1);
namespace TarBSD\Builder;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

use TarBSD\Util\FreeBSDRelease;
use TarBSD\Configuration;
use TarBSD\Util\Overlay;
use TarBSD\Util\Icons;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
use TarBSD\Util\Strs;
use TarBSD\App;

class Installer implements Icons
{
    use Utils;

    private readonly string $filesDir;

    public function __construct(
        private readonly string $root,
        private readonly string $wrk,
        private readonly WrkFs $wrkFs,
        private readonly ?FreeBSDRelease $baseRelease,
        private readonly Filesystem $fs,
        private readonly Configuration $config,
        private readonly HttpClientInterface $httpClient
    ) {
        $this->filesDir = $config->getDir() . '/tarbsd';
    }

    final public function installPkgBase(
        OutputInterface $output, OutputInterface $verboseOutput, string $arch
    ) : void {

        $abi = $this->baseRelease->getAbi($arch);

        $distFileHash = hash('xxh128', json_encode([
            $this->baseRelease->getBaseRepo($arch),
            gmdate('Y-m-d'),
            App::hashPhar()
        ]));

        if (
            !file_exists($distFileHashFile = $this->wrk . '/distFileHash')
            || file_get_contents($distFileHashFile) !== $distFileHash
        ) {
            $this->wrkFs->destroySnapshot('installed');
        }

        if ($this->wrkFs->hasSnapshot('installed'))
        {
            $output->writeln(self::CHECK . $msg = sprintf(
                ' base system (%s) unchanged, using snapshot',
                $this->getInstalledVersion()
            ));
            $verboseOutput->writeln($msg);
        }
        else
        {
            $this->wrkFs->rollback('empty');
            $this->testRepo($this->baseRelease->getBaseRepo($arch) . '/meta');

            $this->fs->dumpFile(
                $pkgConf = $this->root . '/usr/local/etc/pkg/repos/FreeBSD-base.conf',
                $this->baseRelease->getBaseConf()
            );

            $this->fs->mirror(
                $pkgKeys = '/usr/share/keys',
                $rootPkgKeys = $this->root . $pkgKeys
            );

            foreach(Yaml::parse(Strs::KEYS_YML) as $release => $keys)
            {
                if (!$this->fs->exists($dir = $rootPkgKeys . '/' . $release . '/trusted'))
                {
                    foreach($keys['trusted'] as $key => $data)
                    {
                        $this->fs->dumpFile(
                            $dir . '/' . $key,
                            sprintf(
                                "function: \"%s\"\nfingerprint: \"%s\"\n",
                                $data['function'], $data['fingerprint']
                            )
                        );
                    }
                }
                if (!$this->fs->exists($revoked = $rootPkgKeys . '/' . $release . '/revoked'))
                {
                    $this->fs->mkdir($revoked);
                }
            }

            $this->fs->dumpFile($this->root . '/etc/resolv.conf', Overlay::RESOLV_CONF);

            $this->fs->mkdir($pkgCache = App::CACHE_DIR . '/pkgbase_' . $arch);
            $umountPkgCache = $this->preparePKG($pkgCache, false);

            try
            {
                $pkg = sprintf(
                    'pkg --rootdir %s --repo-conf-dir %s -o IGNORE_OSVERSION=yes -o ABI=%s -o OSVERSION=%s ',
                    $this->root,
                    dirname($pkgConf),
                    $abi,
                    $this->baseRelease->getOsVersion()
                );
    
                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('downloading base packages');
                Process::fromShellCommandline(
                    $pkg . ' update', null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
                $availableBasePkgs = Process::fromShellCommandline(
                    $pkg . ' search FreeBSD-'
                )->mustRun()->getOutput();
                $basePkgRegex = explode("\n", Strs::BASE_PKGS);
                $basePkgRegex[] = 'kernel-generic';
                $basePkgRegex = sprintf(
                    '/^(FreeBSD-(%s))-([1-9][0-9])/',
                    implode('|', $basePkgRegex)
                );
                $pkgs = [];
                foreach(explode("\n", $availableBasePkgs) as $pkgName)
                {
                    if (preg_match($basePkgRegex, $pkgName, $m))
                    {
                        $pkgs[] = $m[1];
                    }
                }
                $pkgs = " \\\n" . implode(" \\\n", $pkgs);
                $existingPkgs = $this->listPkgs($pkgCache);
                Process::fromShellCommandline(
                    $pkg . ' install -U -F -y ' . $pkgs, null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
                foreach(array_diff($this->listPkgs($pkgCache), $existingPkgs) as $package)
                {
                    $this->fs->touch($package);
                }

                $progressIndicator->setMessage('installing base packages');
                $installCmd = $pkg . ' install -U -y ' . $pkgs;
                $verboseOutput->writeln($installCmd);
                Process::fromShellCommandline(
                    $installCmd, null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
                $progressIndicator->finish(sprintf(
                    "%s installed",
                    $this->getInstalledVersion()
                ));

                $umountPkgCache->mustRun();
                $this->fs->remove($pkgConf);

                $this->finalizeInstall();
                file_put_contents($distFileHashFile, $distFileHash);
                $this->applyPruneList(Strs::PRUNELIST);
                $this->wrkFs->snapshot('installed');
            }
            catch (\Exception $e)
            {
                $umountPkgCache->mustRun();
                throw $e;
            }
        }
    }

    final protected function finalizeInstall() : void
    {
        $this->fs->mkdir($this->root . '/boot/modules');
        $this->fs->mkdir($this->root . '/usr/local/etc/pkg');
        $this->fs->remove($varTmp = $this->root . '/var/tmp');
        $this->fs->symlink('../tmp', $varTmp);
        $this->fs->appendToFile($this->root. '/etc/ssh/sshd_config', <<<SSH
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin yes

SSH);
        $this->fs->appendToFile($this->root. '/etc/defaults/rc.conf', <<<DEFAULTS
entropy_boot_file="NO"
entropy_file="NO"
clear_tmp_X="NO"
varmfs="NO"
tarbsdinit_enable="YES"
tarbsd_zpool_enable="YES"

DEFAULTS);
    }

    final public function installPKGs(OutputInterface $output, OutputInterface $verboseOutput, string $arch) : void
    {
        $packages = $this->getRequiredPackages();

        sort($packages);
        $packagesHash = hash_init('xxh128');
        hash_update($packagesHash, json_encode($packages));

        if (file_exists($pkgConfigDir = $this->filesDir . '/usr/local/etc/pkg'))
        {
            hash_update($packagesHash, (string) filemtime($pkgConfigDir));
        }
        $packagesHash = hash_final($packagesHash);

        if (
            !file_exists($packagesHashFile = $this->wrk . '/packagesHash')
            || file_get_contents($packagesHashFile) !== $packagesHash
        ) {
            $this->wrkFs->destroySnapshot('pkgsInstalled');
        }

        if ($this->wrkFs->hasSnapshot('pkgsInstalled'))
        {
            $output->writeln(self::CHECK . $msg = ' package list unchanged, using snapshot');
            $verboseOutput->writeln($msg);
            $this->wrkFs->rollback('pkgsInstalled');
        }
        else
        {
            $this->testRepo($this->baseRelease->getLatestRepo($arch) . '/meta');

            $this->wrkFs->rollback('installed');
            $this->fs->mkdir($this->wrk . '/cache');
            if (count($packages) > 0)
            {
                if (file_exists($pkgConfigDir))
                {
                    $this->fs->mkdir($target = $this->root . '/usr/local/etc/pkg');
                    Misc::tarStream($pkgConfigDir, $target, $verboseOutput);
                }
                $this->fs->mkdir(
                    $cache = $this->wrk . '/cache/pkg-' . $this->getInstalledVersion(false) . '-' . $arch
                );
                $umountPkg = $this->preparePKG($cache, true);

                try
                {
                    $pkg = sprintf('pkg -c %s ', $this->root);

                    $progressIndicator = $this->progressIndicator($output);    
                    $progressIndicator->start('updating package database');
                    $this->wrkFs->tightCompression(false);
                    Process::fromShellCommandline(
                        $pkg . ' update', null, null, null, 7200
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });
                    $this->wrkFs->tightCompression(true);

                    $progressIndicator->setMessage('downloading packages');
                    Process::fromShellCommandline(
                        $pkg . ' install -F -y -U ' . implode(' ', $packages),
                        null, null, null, 7200
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });

                    $progressIndicator->setMessage('installing packages');
                    $installCmd = $pkg . ' install -U -y ' . implode(' ', $packages);
                    $verboseOutput->writeln($installCmd);
                    Process::fromShellCommandline(
                        $installCmd,
                        null, null, null, 7200
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });
                    $progressIndicator->finish('packages installed');
                }
                catch (\Exception $e)
                {
                    $umountPkg->mustRun();
                    throw $e;
                }
                $umountPkg->mustRun();
            }
            else
            {
                $output->writeln(self::CHECK . $msg = ' no packages to install');
            }
            file_put_contents($packagesHashFile, $packagesHash);
            $this->applyPruneList(Strs::PRUNELIST);
            $this->wrkFs->snapshot('pkgsInstalled');
        }
    }

    final protected function preparePKG(string $cacheLocation, bool $tmpfs) : Process
    {
        $nullfs = $this->root . '/var/cache/pkg';
        $pkgdb = $this->root . '/var/db/pkg';
        $this->fs->mkdir($nullfs);

        if ($tmpfs)
        {
            Process::fromShellCommandline(sprintf(
                'mount_nullfs -o rw %s %s && mount -t tmpfs tmpfs %s',
                $cacheLocation, $nullfs, $pkgdb
            ))->mustRun();
    
            return Process::fromShellCommandline(sprintf(
                'umount -f %s && umount -f %s',
                $nullfs, $pkgdb
            ));
        }

        Process::fromShellCommandline(sprintf(
            'mount_nullfs -o rw %s %s',
            $cacheLocation, $nullfs
        ))->mustRun();

        return Process::fromShellCommandline(sprintf(
            'umount -f %s',
            $nullfs
        ));
    }

    final protected function getInstalledVersion(bool $patch = true) : string
    {
        $out = trim(
            Process::fromShellCommandline('bin/freebsd-version', $this->root)->mustRun()->getOutput(),
            "\n"
        );
        if (!$patch)
        {
            $out = preg_replace('/(\-p[0-9]{1,2})$/', '', $out);
        }
        return $out;
    }

    protected function listPkgs(string $cacheDir) : array
    {
        $f = (new Finder)->files()->in($cacheDir);

        return array_keys(iterator_to_array($f));
    }

    protected function testRepo(string $url, int $n = 0) : void
    {
        static $okDomains = [];

        $domain = parse_url($url, PHP_URL_HOST);

        if ($n > 1 || in_array($domain, $okDomains))
        {
            return;
        }

        $n++;

        try
        {
            $res = $this->httpClient->request('HEAD', $url, [
                'max_redirects' => 0,
            ]);
            $statusCode = $res->getStatusCode();
        }
        catch(\Exception $e)
        {
            if (preg_match('/dns/', $e->getMessage()))
            {
                throw new \Exception(sprintf(
                    "DNS error, cannot connect to %s",
                    $domain
                ));
            }
            throw new \Exception(sprintf(
                "Network error, cannot connect to %s",
                $domain
            ));
        }

        switch($statusCode)
        {
            case 200:
                $okDomains[] = $domain;
                break;
            case 301:
            case 302:
                /***
                 * Base packages are hosted at a different repo (cloudfront.aws.pkgbase.freebsd.org)
                 * for RELEASE|ALPHA|BETA versions of FreeBSD, pkg.freebsd.org redirects there.
                 ***/
                $okDomains[] = $domain;
                $info = $res->getInfo();
                $redirectUrl = $info['redirect_url'];
                if ($domain !== parse_url($redirectUrl, PHP_URL_HOST))
                {
                    $this->testRepo($redirectUrl, $n);
                }
                break;
            case 404:
                throw new \Exception(sprintf(
                    'Seems like %s doesn\'t exist',
                    $this->baseRelease
                ));
            default:
                throw new \Exception(sprintf(
                    'There\'s something wrong with %s, status code: %s',
                    $domain,
                    $statusCode
                ));
        }
    }
}
