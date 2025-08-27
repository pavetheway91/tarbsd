<?php
namespace TarBSD\Builder\Traits;

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
            $abi, $this->baseRelease->getBaseRepo()
        ]));

        if (!is_dir($pkgCache = '/var/cache/tarbsd/pkgbase'))
        {
            $this->fs->mkdir($pkgCache);
        }

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

            $this->fs->mkdir($localPkgCache = $this->root . '/var/cache/pkg');

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

                $p = Process::fromShellCommandline($pkg . ' search Free')->mustRun();

                $pkgs = ['FreeBSD-kernel-generic'];

                foreach(explode("\n", $p->getOutput()) as $line)
                {
                    if ($line)
                    {
                        list($pkgName, $desc) = explode(" ", $line);
                        $pkgName = explode('-', $pkgName);
                        if ($pkgName)
                        {
                            array_pop($pkgName);
                            $pkgName = implode('-', $pkgName);
                            if (!preg_match(
                                '/-(lib|dbg|man|kernel|tests|toolchain|clang|sendmail|src)/',
                                $pkgName
                            )) {
                                $pkgs[] = $pkgName;
                            }
                        }
                    }
                }

                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('downloading base packages');
                Process::fromShellCommandline(
                    $pkg . ' install -U -F -y ' . implode(' ', $pkgs), null, null, null, 1800
                )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                {
                    $progressIndicator->advance();
                    $verboseOutput->write($buffer);
                });
                $progressIndicator->finish('base packages downloaded');

                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('installing base packages');
                Process::fromShellCommandline(
                    $pkg . ' install -U -y ' . implode(' ', $pkgs), null, null, null, 1800
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
        $this->fs->mkdir($this->root . '/var/cache/pkg');
        $this->fs->mkdir($this->root . '/usr/local/etc/pkg');
        $this->fs->remove($varTmp = $this->root . '/var/tmp');
        $this->fs->symlink('../tmp', $varTmp);
        $this->fs->appendToFile($this->root. '/etc/ssh/sshd_config', <<<SSH
PasswordAuthentication no
PermitRootLogin yes

SSH);
        $this->fs->appendToFile($this->root. '/etc/defaults/rc.conf', <<<DEFAULTS
entropy_boot_file="NO"
entropy_file="NO"
clear_tmp_X="NO"
varmfs="NO"
tarbsdinit_enable="YES"

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

            if (file_exists($pkgConfigDir))
            {
                $this->fs->mkdir($target = $this->root . '/usr/local/etc/pkg');
                $this->tarStream($pkgConfigDir, $target, $verboseOutput);
            }

            $umountPkgCache = $this->preparePKG($this->wrk . '/cache/pkg');

            $this->fs->copy(
                TARBSD_STUBS . '/overlay/etc/resolv.conf',
                $this->root . '/etc/resolv.conf'
            );

            try
            {
                $progressIndicator = $this->progressIndicator($output);
                $progressIndicator->start('installing packages');
                Process::fromShellCommandline(
                    'pkg -c ' . $this->wrk . '/root install -y ' . implode(' ', $packages),
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
            file_put_contents($packagesHashFile, $packagesHash);
            $this->snapshot('pkgsInstalled');
        }
    }

    final protected function preparePKG(string $cacheLocation) : Process
    {
        $pkgCache = $this->root . '/var/cache/pkg';

        $this->fs->mkdir($this->wrk . '/cache/pkg');

        Process::fromShellCommandline(
            'mount_nullfs -o rw ' . $cacheLocation . ' ' . $pkgCache
        )->mustRun();

        return Process::fromShellCommandline(
            'umount -f ' . $pkgCache
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
