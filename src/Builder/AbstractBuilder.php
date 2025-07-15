<?php declare(strict_types=1);
namespace TarBSD\Builder;

use TarBSD\Configuration;
use TarBSD\Util\Fstab;
use TarBSD\App;

use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use DateTimeImmutable;
use SplFileInfo;

abstract class AbstractBuilder implements EventSubscriberInterface
{
    use Traits\SignalHandler;

    use Traits\Installer;

    use Traits\Utils;

    const CHECK = ' <g>✔</>';

    const ERR = ' <r>‼️</>';

    protected readonly string $wrk;

    protected readonly string $root;

    protected readonly string $fsId;

    protected readonly string $filesDir;

    protected bool $bootPruned;

    protected ?array $modules;

    protected readonly Filesystem $fs;

    abstract protected function genFsTab() : Fstab;

    abstract protected function prepare(
        OutputInterface $output, OutputInterface $verboseOutput, bool $quick
    ) : void;

    abstract protected function pruneBoot(
        OutputInterface $output, OutputInterface $verboseOutput
    ) : void;

    abstract protected function buildImage(
        OutputInterface $output, OutputInterface $verboseOutput, bool $quick
    ) : void;

    final public function __construct(
        private readonly Configuration $config,
        private readonly CacheInterface $cache,
        private readonly string $distributionFiles,
        private readonly EventDispatcher $dispatcher
    ) {
        $this->wrk = $config->getDir() . '/wrk';
        $this->root = $this->wrk . '/root';
        $this->filesDir = $config->getDir() . '/tarbsd';
        $this->fsId = 'tarbsd_' . substr(md5($this->wrk), 0, 8);

        /**
         * todo: decorate this in a way
         * that it tells verbose output
         * what it does
         **/
        $this->fs = new Filesystem;

        if (!$this->fs->exists($this->filesDir))
        {
            throw new \Exception(sprintf(
                "%s directory does not exist",
                $this->filesDir
            ));
        }
    }

    final public static function getSubscribedEvents() : array
    {
        return [
            ConsoleEvents::SIGNAL   => 'handleSignal',
        ];
    }

    final public function build(OutputInterface $output, OutputInterface $verboseOutput, bool $quick) : SplFileInfo
    {
        $this->dispatcher->addSubscriber($this);

        $start = time();
        $this->bootPruned = false;
        $this->modules = null;

        // todo: do this somewhere else and have a way to configure the pool size
        try
        {
            Process::fromShellCommandline('zfs get all ' . $this->fsId)->mustRun();
        }
        catch (\Exception $e)
        {
            $this->fs->mkdir($this->wrk);
            $md = trim(
                Process::fromShellCommandline('mdconfig -s 2g')->mustRun()->getOutput(),
                "\n"
            );
            Process::fromShellCommandline(
                'zpool create -o ashift=12 -O tarbsd:md=' . $md . ' -m '
                . $this->wrk . ' ' . $this->fsId . ' /dev/' . $md . "\n"
                . 'zfs create -o compression=lz4 -o recordsize=4m '.  $this->fsId . "/root\n"
                . 'zfs snapshot -r ' . $this->fsId . "/root@empty \n"
            )->mustRun();
        }

        $f = (new Finder)->files()->in($this->wrk)->name(['*.img', 'tarbsd.*']);
        $this->fs->remove($f);

        $this->ensureSSHkeysExist($output, $verboseOutput);

        $this->installFreeBSD($output, $verboseOutput);

        $this->installPKGs($output, $verboseOutput);

        $this->prune($output, $verboseOutput);

        $this->tarStream($this->filesDir, $this->root, $verboseOutput);
        $output->writeln(self::CHECK . ' copied overlay directory to the image');

        $this->prepare($output, $verboseOutput, $quick);

        if ($this->config->backup())
        {
            $this->backup($output, $verboseOutput);
        }

        if ($this->config->isBusyBox())
        {
            $this->busyBoxify($output, $verboseOutput);
        }

        $this->finalizeRoot($output, $verboseOutput);

        $this->buildImage($output, $verboseOutput, $quick);

        $cwd = getcwd();

        $output->writeln(sprintf(
            self::CHECK . " %s <info>size %sm</>, generated in %d seconds",
            substr($file = $this->wrk . '/tarbsd.img', strlen($cwd) + 1),
            $this->getFileSizeM($file),
            time() - $start
        ));

        $this->dispatcher->removeSubscriber($this);

        return new SplFileInfo($file);
    }

    final protected function prune(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $pruneList = [
            'rescue'// should this be a feature?
        ];

        foreach(explode("\n", file_get_contents(TARBSD_STUBS . '/prunelist')) as $line)
        {
            if (strlen($line) > 0 && $line[0] !== '#')
            {
                $pruneList[] = $line;
            }
        }

        foreach($this->config->features() as $feature)
        {
            if (!$feature->isEnabled())
            {
                foreach($feature->getPruneList() as $line)
                {
                    $pruneList[] = $line;
                }
            }
        }
        switch($this->config->getSSH())
        {
            case 'dropbear':
            case null:
                $pruneList[] = 'usr/bin/ssh*';
                $pruneList[] = 'usr/sbin/sshd';
                $pruneList[] = 'etc/ssh/*_config';
                $pruneList[] = 'usr/lib/libprivatessh.*';
                // are these needed by anything else than OpenSSH?
                $pruneList[] = 'usr/lib/lib*krb*';
                $pruneList[] = 'usr/lib/libgssapi*';
                $pruneList[] = 'usr/lib/libhx509*';
                $pruneList[] = 'usr/lib/libasn1*';
                $pruneList[] = 'usr/lib/libprivateldns*';
                $pruneList[] = 'usr/lib/libprivatefido2*';
                $pruneList[] = 'usr/lib/libprivatecbor*';
                break;
            case 'openssh':
                break;
            default:
                throw new \Exception(sprintf(
                    'unknown SSH client %s, valid values are dropbear, openssh and null',
                    $this->config->getSSH()
                ));
        }
        foreach($pruneList as $index => $line)
        {
            $pruneList[$index] = 'rm -rf ' . $line;
        }

        Process::fromShellCommandline(implode("\n", $pruneList), $this->root)->mustRun();
        $output->writeln(self::CHECK . ' pruned dev tools, manpages and disabled features');
    }

    final protected function finalizeRoot(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $this->pruneBoot($output, $verboseOutput);
        $this->bootPruned = true;
        $fs = $this->fs;

        $fstab = $this->genFsTab($output);
        $fstab->addLine('/.usr.tar', '/usr', 'tarfs', 'ro,as=tarfs');

        foreach([
            'fdescfs' =>    '/dev/fd',
            'procfs'  =>    '/proc'
        ] as $pseudoFs => $mnt)
        {
            if ($this->hasKernelModule($pseudoFs))
            {
                $fstab->addLine($pseudoFs, $mnt, $pseudoFs, 'rw');
            }
        }
        foreach(['linprocfs', 'linsysfs'] as $linPseudoFs)
        {
            if (
                $this->hasKernelModule($linPseudoFs)
                && $this->hasKernelModule('linux_common')
            ) {
                $baseName = substr($linPseudoFs, 0, -2);
                $fstab->addLine(
                    $baseName,
                    $mnt = '/compat/linux/' . substr($baseName, 3),
                    $linPseudoFs,
                    'rw'
                );
                $this->fs->mkdir($this->root . $mnt);
            }
            $this->fs->symlink('../../../../tmp', $this->root . '/compat/linux/dev/shm');
        }

        if ($this->fs->exists($fstabFile = $this->root . '/etc/fstab'))
        {
            $fstab->addEmptyLine();
            $fstab->addComment('lines above this were auto-generated by tarBSD builder');
            $fstab->addEmptyLine();
            $fstab->append(Fstab::fromFile($fstabFile));
        }
        $this->fs->dumpFile($fstabFile, $fstab);
        $output->writeln(self::CHECK . ' fstab generated');

        $fs->appendToFile($this->root . '/COPYRIGHT', sprintf(
            "\n\n\ntarBSD builder and files associated with it are distributed under\n"
            . "following terms:\n\n%s\n",
            file_get_contents(TARBSD_STUBS . '/../LICENSE')
        ));

        $fs->mirror(TARBSD_STUBS . '/rc.d', $this->root . '/etc/rc.d/', null, [
            'delete' => false
        ]);

        $fs->copy(TARBSD_STUBS . '/motd', $this->root . '/etc/motd.template');

        $pwHash = $this->config->getRootPwHash();
        $key = $this->config->getRootSshKey();

        if ($pwHash)
        {
            Process::fromShellCommandline(
                'pw -V ' . $this->root . '/etc usermod root -H 0', null, null, $pwHash
            )->mustRun();
            if (!$key)
            {
                $output->writeln(self::CHECK . ' root password set');
            }
        }
        if ($key)
        {
            $fs->appendToFile($file = $this->root. '/root/.ssh/authorized_keys', $key);
            $fs->chmod($file, 0700);
            if (!$pwHash)
            {
                $output->writeln(self::CHECK . ' root ssh key set');
            }
        }
        if ($key && $pwHash)
        {
            $output->writeln(self::CHECK . ' root password and ssh key set');
        }

        switch($this->config->getSSH())
        {
            case 'dropbear':
                $dropbearDir = $this->root . '/usr/local/etc/dropbear/';
                foreach(['ed25519', 'rsa', 'ecdsa'] as $alg)
                {
                    $fs->symlink(
                        '../../../../var/run/dropbear/dropbear_' . $alg . '_host_key',
                        $dropbearDir . 'dropbear_' . $alg . '_host_key'
                    );
                    $fs->symlink(
                        '../../../../etc/ssh/ssh_host_' . $alg . '_key.pub',
                        $dropbearDir . 'dropbear_' . $alg . '_host_key.pub'
                    );
                }
                $fs->appendToFile($this->root. '/etc/defaults/rc.conf', "dropbear_enable=\"YES\"\n");
                $fs->appendToFile($this->root. '/etc/defaults/rc.conf', "dropbear_args=\"-s\"\n");
                $output->writeln(self::CHECK . ' dropbear enabled');
                break;
            case 'openssh':
                $fs->appendToFile($this->root. '/etc/defaults/rc.conf', "sshd_enable=\"YES\"\n");
                $output->writeln(self::CHECK . ' openssh enabled');
                break;
        }
    }

    final protected function gzipFiles(Finder $f, OutputInterface $output, OutputInterface $verboseOutput, bool $quick) : void
    {
        $expiration = new DateTimeImmutable('+3 months');

        foreach($f as $file)
        {
            $zlibItem = $this->cache->getItem(
                hash_hmac_file('sha1', (string) $file, 'zlib')
            );
            $zopfliItem = $this->cache->getItem(
                hash_hmac_file('sha1', (string) $file, 'zopfli')
            );

            if ($zopfliItem->isHit())
            {
                $output->write(self::CHECK . ' ' . $file->getFilename() . '.gz (compressed using zopfli) cached', true);
                file_put_contents($file . '.gz', $zopfliItem->get());
                unlink((string) $file);
            }
            else
            {
                if ($this->hasZopfli() && !$quick)
                {
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start(sprintf(
                        "compressing %s using zopfli, might take a while, will be cached.",
                        $file->getFilename(),
                    ));

                    $p = Process::fromShellCommandline(
                        'zopfli -v ' . $file,
                        null, null, null, 1800
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });
                    $progressIndicator->finish($file->getFilename() . ' compressed');

                    $zopfliItem->set(file_get_contents($file . '.gz'))->expiresAt($expiration);
                    unlink((string) $file);
                    $this->cache->save($zopfliItem);
                }
                elseif ($zlibItem->isHit())
                {
                    $output->write(self::CHECK . ' ' . $file->getFilename() . '.gz cached', true);
                    file_put_contents($file . '.gz', $zlibItem->get());
                    unlink((string) $file);
                }
                else
                {
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start(sprintf(
                        "compressing %s",
                        $file->getFilename(),
                    ));
    
                    $p = Process::fromShellCommandline(
                        'gzip -v -9 ' . $file
                    )->mustRun(function ($type, $buffer) use ($progressIndicator, $verboseOutput)
                    {
                        $progressIndicator->advance();
                        $verboseOutput->write($buffer);
                    });
                    $progressIndicator->finish($file->getFilename() . ' compressed');

                    $zlibItem->set(file_get_contents($file . '.gz'))->expiresAt($expiration);
                    $this->cache->save($zlibItem);
                }
            }
        }
    }

    private function busyBoxify(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $progressIndicator = $this->progressIndicator($output);
        $progressIndicator->start('busybofiying');
        
        $bysyBoxCMDs = explode("\n", file_get_contents(TARBSD_STUBS . '/busybox'));
        $bysyBoxCMDs = array_flip($bysyBoxCMDs);

        $fs = $this->fs;

        $this->fs->rename(
            $this->root . '/usr/local/bin/busybox',
            $this->root . '/bin/busybox'
        );

        foreach(['bin', 'sbin'] as $dir)
        {
            $f = (new Finder)->files()->in([$this->root . '/usr/' . $dir]);
            foreach($f as $bin)
            {
                $name = $bin->getFileName();
                if (
                    !$bin->isLink()
                    && !preg_match('/^('
                        . 'ssh|syslo|newsys|cron|jail|jex|jls|bhyve|peri'
                        . '|ifcon|dhcli|find|install|du|wall|service'
                        . '|env|utx|limits|automount|ldd|tar|bsdtar|pw'
                        . '|ip6add|fetch|drill|wpa_|mtree|ntpd|uname|passwd'
                        . '|login|su'
                        . '|(?:[a-z]+(pass|user))'
                    . ')/', $name)
                ) {
                    $path = $this->root . '/usr/' . $dir . '/' . $name;
                    $this->fs->remove($path);
                    if (isset($bysyBoxCMDs[$name]))
                    {
                        $this->fs->symlink('../../bin/busybox', $path);
                    }
                }
                $progressIndicator->advance();
            }
        }

        $f = (new Finder)->files()->in([$this->root . '/bin/']);
        foreach($f as $bin)
        {
            foreach($f as $bin)
            {
                if (
                    !$bin->isLink()
                    && !preg_match('/^('
                        . 'sh|expr|ln'
                    . ')/', $name = $bin->getFileName())
                ) {
                    if (isset($bysyBoxCMDs[$name]))
                    {
                        /*
                        $this->fs->hardlink(
                            $this->root . '/bin/busybox',
                            $bin
                        );*/
                        $path = $this->root . '/bin/' . $name;
                        $this->fs->remove($path);
                        $this->fs->symlink('busybox', $path);
                    }
                }
                $progressIndicator->advance();
            }
        }

        $f = (new Finder)->files()->in([$this->root . '/sbin/']);
        foreach($f as $bin)
        {
            foreach($f as $bin)
            {
                if (!$bin->isLink())
                {
                    $name = $bin->getFileName();
                    if (isset($bysyBoxCMDs[$name]))
                    {
                        $path = $this->root . '/sbin/' . $name;
                        $this->fs->remove($path);
                        $this->fs->symlink('../bin/busybox', $path);
                    }
                }
                $progressIndicator->advance();
            }
        }

        $progressIndicator->finish('busyboxified');
    }

    private function backup(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $dir = $this->config->getDir();
        $backupFile = $this->root . '/root/tarbsdBackup.tar';

        $tarOptions = $this->encodeTarOptions([
            'compression-level' => 19,
            'min-frame-in'      => '1M',
            'max-frame-in'      => '8M',
            'frame-per-file'    => true,
            'threads'           => 0
        ]);

        Process::fromShellCommandline(
            "tar -v --zstd --options zstd:$tarOptions -cf " . $backupFile . " tarbsd.yml tarbsd",
            $dir,
        )->mustRun(function ($type, $buffer) use ($verboseOutput)
        {
            $verboseOutput->write($buffer);
        });

        $output->writeln(
            self::CHECK . $msg = ' backed up tarbsd.yml and the overlay directory to the image'
        );
        $verboseOutput->writeln($msg);
    }
}
