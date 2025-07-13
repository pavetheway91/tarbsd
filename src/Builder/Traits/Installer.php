<?php
namespace TarBSD\Builder\Traits;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use GuzzleHttp\Client as Guzzle;

trait Installer
{
    final protected function installFreeBSD(OutputInterface $output, OutputInterface $verboseOutput) : void
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
            file_put_contents($distFileHashFile, $distFileHash);
            $this->snapshot('installed');
        }
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

            $umountPkgCache = $this->preparePKG();

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

    final protected function preparePKG() : Process
    {
        $pkgCache = $this->root . '/var/cache/pkg';

        $this->fs->mkdir($this->wrk . '/cache/pkg');

        Process::fromShellCommandline(
            'mount_nullfs -o rw ' . $this->wrk . '/cache/pkg ' . $pkgCache
        )->mustRun();

        return Process::fromShellCommandline(
            'umount -f ' . $pkgCache
        );
    }

    /******************************************
     * To be used with pkgbase.
     * 
     * Fetches pkgbase's package catalog and parses
     * it. Based on result, we'll somehow figure if
     * update is available. Saves time compared to
     * invoking pkg just for this little piece
     * of info.
     ******************************************/
    final public static function fetchPKGBase(string $arch, string $release) : array
    {
        $guzzle = new Guzzle;

        [$version, $branch] = explode('-', $release);

        [$major, $minor] = explode('.', $version);

        $uri = 'https://pkg.freebsd.org/FreeBSD:';

        switch($branch)
        {
            case 'RELEASE':
                $uri .= sprintf(
                    '%d:%s/base_release_%d/',
                    $major,
                    $arch,
                    $minor
                );
                break;
        }

        $res = $guzzle->request('GET', $uri . 'data.pkg');

        if ($res->getStatusCode() == 200)
        {
            /**
             * It's just tarred json
             */
            $p = Process::fromShellCommandline(
                'tar -xO - data',
                null,
                null,
                $res->getBody()->getContents(),
            )->mustRun();

            $data = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            return $data['packages'];
        }
    }
}
