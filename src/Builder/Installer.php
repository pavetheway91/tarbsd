<?php declare(strict_types=1);
namespace TarBSD\Builder;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

use TarBSD\Util\FreeBSDRelease;
use TarBSD\Configuration;
use TarBSD\Util\Icons;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
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
        private readonly ?string $distributionFiles,
        private readonly Filesystem $fs,
        private readonly Configuration $config,
        private readonly HttpClientInterface $httpClient
    ) {
        $this->filesDir = $config->getDir() . '/tarbsd';
    }

    final public function installPkgBase(OutputInterface $output, OutputInterface $verboseOutput, string $arch) : void
    {
        $rootId = $this->wrkFs . '/root';

        $abi = $this->baseRelease->getAbi($arch);

        $distFileHash = hash('xxh128', json_encode([
            $this->baseRelease->getBaseRepo($arch),
            gmdate('Y-m-d'),
            TARBSD_BUILD_ID
        ]));

        if (
            !file_exists($distFileHashFile = $this->wrk . '/distFileHash')
            || file_get_contents($distFileHashFile) !== $distFileHash
        ) {
            Process::fromShellCommandline('zfs destroy -r ' . $rootId . '@installed')->run();
        }

        try
        {
            Process::fromShellCommandline('zfs get all ' . $rootId . '@installed')->mustRun();
            $output->writeln(self::CHECK . $msg = sprintf(
                ' base system (%s) unchanged, using snapshot',
                $this->getInstalledVersion()
            ));
            $verboseOutput->writeln($msg);
        }
        catch (\Exception $e)
        {
            $this->wrkFs->rollback('empty');

            $res = $this->httpClient->request('GET', $url = $this->baseRelease->getBaseRepo($arch), [
                'max_redirects' => 0,
            ]);
            switch($res->getStatusCode())
            {
                case 200:
                case 301:
                case 302:
                    break;
                case 404:
                    throw new \Exception(sprintf(
                        'Seems like %s doesn\'t exist',
                        $this->baseRelease
                    ));
                default:
                    throw new \Exception(sprintf(
                        'Seems like there\'s something wrong in %s, status code: %s',
                        $this->baseRelease::PKG_DOMAIN,
                        $res->getStatusCode()
                    ));
            }

            $this->fs->dumpFile(
                $pkgConf = $this->root . '/usr/local/etc/pkg/repos/FreeBSD-base.conf',
                $this->baseRelease->getBaseConf()
            );

            $this->fs->mirror(
                $pkgKeys = '/usr/share/keys',
                $rootPkgKeys = $this->root . $pkgKeys
            );
            $f = (new Finder)->directories()->in(TARBSD_STUBS . '/keys')->depth(0);
            foreach($f as $dir)
            {
                if (!$this->fs->exists($target = $rootPkgKeys . '/' . $dir->getFileName()))
                {
                    $this->fs->mirror((string) $dir, $target);
                    if (!$this->fs->exists($revoked = $target . '/revoked'))
                    {
                        $this->fs->mkdir($revoked);
                    }
                }
            }
            $this->fs->copy(
                TARBSD_STUBS . '/overlay/etc/resolv.conf',
                $this->root . '/etc/resolv.conf'
            );
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
                $basePkgRegex = explode("\n", file_get_contents(TARBSD_STUBS . '/basepkgs'));
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
                Process::fromShellCommandline(
                    $pkg . ' install -U -F -y ' . $pkgs, null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
                $progressIndicator->finish('base packages downloaded');

                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('installing base packages');
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
                $this->wrkFs->snapshot('installed');
            }
            catch (\Exception $e)
            {
                $umountPkgCache->mustRun();
                throw $e;
            }
        }
    }

    final public function installTarBalls(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $rootId = $this->wrkFs . '/root';
        $distFiles = [];
        $distFileHash = hash_init('xxh128');
        foreach(['kernel.txz', 'base.txz'] as $file)
        {
            if (!file_exists($fullPath = $this->distributionFiles . $file))
            {
                throw new \Exception;
            }
            hash_update_file($distFileHash, $fullPath);
            $distFiles[$file] = $fullPath;
        }
        if (TARBSD_BUILD_ID)
        {
            hash_update($distFileHash, TARBSD_BUILD_ID);
        }
        $distFileHash = hash_final($distFileHash);

        if (
            !file_exists($distFileHashFile = $this->wrk . '/distFileHash')
            || file_get_contents($distFileHashFile) !== $distFileHash
        ) {
            Process::fromShellCommandline('zfs destroy -r ' . $rootId . '@installed')->run();
        }

        try
        {
            Process::fromShellCommandline('zfs get all ' . $rootId . '@installed')->mustRun();
            $output->writeln(self::CHECK . $msg = ' base system unchanged, using snapshot');
            $verboseOutput->writeln($msg);
        }
        catch (\Exception $e)
        {
            $this->wrkFs->rollback('empty');
            foreach($distFiles as $file => $fullPath)
            {
                $cmd = 'tar -xvf ' . $fullPath . ' -C ' . $this->root;

                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('extracting ' . $file);

                Process::fromShellCommandline(
                    $cmd, null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
                $progressIndicator->finish($file . ' extracted');
            }
            $this->fs->copy(
                TARBSD_STUBS . '/overlay/etc/resolv.conf',
                $this->root . '/etc/resolv.conf'
            );
            $this->runFreeBSDUpdate($output, $verboseOutput);
            $this->finalizeInstall();
            file_put_contents($distFileHashFile, $distFileHash);
            $this->wrkFs->snapshot('installed');
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
        $rootId = $this->wrkFs . '/root';

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
            Process::fromShellCommandline('zfs destroy -r ' . $rootId . '@pkgsInstalled')->run();
        }

        try
        {
            Process::fromShellCommandline('zfs get all ' . $rootId . '@pkgsInstalled')->mustRun();
            $output->writeln(self::CHECK . $msg = ' package list unchanged, using snapshot');
            $verboseOutput->writeln($msg);
            $this->wrkFs->rollback('pkgsInstalled');
        }
        catch (\Exception $e)
        {
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
                    Process::fromShellCommandline(
                        $pkg . ' update', null, null, null, 7200
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });
                    $progressIndicator->finish('package database updated');
    
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start('downloading packages');
                    Process::fromShellCommandline(
                        $pkg . ' install -F -y -U ' . implode(' ', $packages),
                        null, null, null, 7200
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });
                    $progressIndicator->finish('packages downloaded');

                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start('installing packages');
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
            file_put_contents($packagesHashFile, $packagesHash);
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

    final protected function runFreeBSDUpdate(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $v = $this->getInstalledVersion();
        $this->fs->mkdir($updateDir = $this->wrk . '/cache/freebsd-update');

        $fetch = sprintf(
            "freebsd-update -b %s -d %s --currently-running %s --not-running-from-cron fetch",
            $this->root,
            $updateDir,
            $v
        );
        $install = sprintf(
            "freebsd-update -b %s -d %s --currently-running %s --not-running-from-cron install",
            $this->root,
            $updateDir,
            $v
        );

        $progressIndicator = $this->progressIndicator($output);
        $progressIndicator->start('running freebsd-update');
        Process::fromShellCommandline(
            $fetch, null, null, null, 1800
        )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
        {
            $progressIndicator->advance();
            $verboseOutput->write($buffer);
        });

        $runInstall = function() use ($install, $progressIndicator, $verboseOutput) : bool
        {
            try
            {
                Process::fromShellCommandline(
                    $install, null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
            }
            catch (\Exception $e)
            {
                if (str_contains($e->getMessage(), 'No updates are available'))
                {
                    // ok
                    return false;
                }
                else
                {
                    throw $e;
                }
            }
            return true;
        };

        // there could be 0, 1 or 2 installs to be run
        $installedSomething = $runInstall();

        if ($installedSomething)
        {
            $runInstall();
            $progressIndicator->finish('updated to ' . $this->getInstalledVersion());
        }
        else
        {
            $progressIndicator->finish('no updates to install');
        }

        $this->fs->remove($updateDir);
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
}
