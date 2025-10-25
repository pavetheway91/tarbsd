<?php declare(strict_types=1);
namespace TarBSD\Builder\Traits;

use TarBSD\App;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

trait Installer
{
    final protected function installPkgBase(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        /**
         * Due to lack of testing others
         */
        $arch = 'amd64';

        $rootId = $this->fsId . '/root';

        $abi = sprintf(
            'FreeBSD:%s:%s',
            $this->baseRelease->major,
            $arch
        );

        $distFileHash = hash('xxh128', json_encode([
            $abi,
            $this->baseRelease->getBaseRepo(),
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
            $output->writeln(self::CHECK . $msg = ' base system unchanged, using snapshot');
            $verboseOutput->writeln($msg);
        }
        catch (\Exception $e)
        {
            $this->rollback('empty');

            $res = $this->httpClient->request('GET', sprintf(
                'https://pkg.freebsd.org/%s/%s/',
                $abi,
                $this->baseRelease->getBaseRepo()
            ));

            switch($res->getStatusCode())
            {
                case 200:
                    break;
                case 404:
                    throw new \Exception(sprintf(
                        'Seems like %s doesn\'t exist',
                        $this->baseRelease
                    ));
                default:
                    throw new \Exception(sprintf(
                        'Seems like there\'s something wrong in pkg.freebsd.org, status code: %s',
                        $res->getStatusCode()
                    ));
            }

            $baseConf = sprintf(
                file_get_contents(TARBSD_STUBS . '/FreeBSD-base.conf'),
                $this->baseRelease->getBaseRepo()
            );
            $this->fs->dumpFile(
                $pkgConf = $this->root . '/usr/local/etc/pkg/repos/FreeBSD-base.conf',
                $baseConf
            );
            $this->fs->mirror(
                $pkgKeys = '/usr/share/keys/pkg',
                $this->root . $pkgKeys
            );

            $this->fs->mkdir($pkgCache = App::CACHE_DIR . '/pkgbase_' . $arch);
            $umountPkgCache = $this->preparePKG($pkgCache);

            try
            {
                $pkg = sprintf(
                    'pkg --rootdir %s --repo-conf-dir %s -o IGNORE_OSVERSION=yes -o ABI=%s ',
                    $this->root,
                    dirname($pkgConf),
                    $abi
                );

                Process::fromShellCommandline(
                    $pkg . ' update', null, null, null, 1800
                )->mustRun();
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

                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('downloading base packages');
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
                $this->snapshot('installed');
            }
            catch (\Exception $e)
            {
                $umountPkgCache->mustRun();
                throw $e;
            }
        }
    }

    final protected function installTarBalls(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $rootId = $this->fsId . '/root';
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
            $this->rollback('empty');
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

            $this->runFreeBSDUpdate($output, $verboseOutput);
            $this->finalizeInstall();
            file_put_contents($distFileHashFile, $distFileHash);
            $this->snapshot('installed');
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

    final protected function installPKGs(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $rootId = $this->fsId . '/root';

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
            $this->rollback('pkgsInstalled');
        }
        catch (\Exception $e)
        {
            $this->rollback('installed');
            $this->fs->mkdir($this->wrk . '/cache');
            if (count($packages) > 0)
            {
                if (file_exists($pkgConfigDir))
                {
                    $this->fs->mkdir($target = $this->root . '/usr/local/etc/pkg');
                    $this->tarStream($pkgConfigDir, $target, $verboseOutput);
                }
                $this->fs->mkdir(
                    $cache = $this->wrk . '/cache/pkg-' . $this->getInstalledVersion()
                );
                $umountPkgCache = $this->preparePKG($cache);
                $this->fs->copy(
                    TARBSD_STUBS . '/overlay/etc/resolv.conf',
                    $this->root . '/etc/resolv.conf'
                );
                try
                {
                    $pkg = sprintf('pkg -c %s ', $this->root);
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start('downloading packages');
                    Process::fromShellCommandline(
                        $pkg . ' update', null, null, null, 7200
                    )->mustRun();
                    Process::fromShellCommandline(
                        $pkg . ' install -F -y ' . implode(' ', $packages),
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
                    $umountPkgCache->mustRun();
                    throw $e;
                }
                $umountPkgCache->mustRun();
            }
            file_put_contents($packagesHashFile, $packagesHash);
            $this->snapshot('pkgsInstalled');
        }
    }

    final protected function preparePKG(string $cacheLocation) : Process
    {
        $nullfs = $this->root . '/var/cache/pkg';

        $this->fs->mkdir($nullfs);

        Process::fromShellCommandline(
            'mount_nullfs -o rw ' . $cacheLocation . ' ' . $nullfs
        )->mustRun();

        return Process::fromShellCommandline(
            'umount -f ' . $nullfs
        );
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

    final protected function getInstalledVersion() : string
    {
        return trim(
            Process::fromShellCommandline('bin/freebsd-version', $this->root)->mustRun()->getOutput(),
            "\n"
        );
    }
}
