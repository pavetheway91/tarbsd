<?php declare(strict_types=1);
namespace TarBSD\Builder;

use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\SignalRegistry\SignalMap;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

use TarBSD\Util\FreeBSDRelease;
use TarBSD\Configuration;
use TarBSD\Util\Icons;
use TarBSD\Util\Fstab;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
use TarBSD\App;

use DateTimeImmutable;
use SplFileInfo;

abstract class AbstractBuilder implements EventSubscriberInterface, Icons
{
    use Utils;

    public readonly WrkFs $wrkFs;

    public readonly string $wrk;

    protected readonly string $root;

    protected readonly string $filesDir;

    protected bool $bootPruned;

    protected ?array $modules;

    public ?string $md = null;

    protected readonly Filesystem $fs;

    private readonly string $distributionFiles;

    private readonly ?FreeBSDRelease $baseRelease;

    abstract protected function genFsTab() : Fstab;

    abstract protected function prepare(
        OutputInterface $output, OutputInterface $verboseOutput, bool $quick, string $platform
    ) : void;

    abstract protected function pruneBoot(
        OutputInterface $output, OutputInterface $verboseOutput
    ) : void;

    abstract protected function buildImage(
        OutputInterface $output, OutputInterface $verboseOutput, bool $quick, string $platform
    ) : void;

    final public function __construct(
        private readonly Configuration $config,
        private readonly CacheInterface $cache,
        private string|FreeBSDRelease $distFilesOrBaseRelease,
        private readonly EventDispatcher $dispatcher,
        private readonly HttpClientInterface $httpClient
    ) {
        $this->wrk = $config->getDir() . '/wrk';
        $this->root = $this->wrk . '/root';
        $this->filesDir = $config->getDir() . '/tarbsd';
        WrkFs::init($this->config->getDir());
        $this->wrkFs = WrkFs::get($this->config->getDir());

        if ($distFilesOrBaseRelease instanceof FreeBSDRelease)
        {
            $this->baseRelease = $distFilesOrBaseRelease;
        }
        else
        {
            $this->baseRelease = null;
            $this->distributionFiles = $distFilesOrBaseRelease;
        }

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

    final public function build(OutputInterface $output, OutputInterface $verboseOutput, bool $quick) : SplFileInfo
    {
        $this->dispatcher->addSubscriber($this);
        $this->wrkFs->tightCompression(true);

        $start = time();
        $this->bootPruned = false;
        $this->modules = null;

        $f = (new Finder)->files()->in($this->wrk)->name(['*.img', 'tarbsd.*']);
        $this->fs->remove($f);

        [$arch, $platform] = $this->config->getPlatform();

        $output->writeln(sprintf(
            self::CHECK . ' building image for <comment>%s</>',
            $platform
        ));

        $this->ensureSSHkeysExist($output, $verboseOutput);

        $installer = new Installer(
            $this->root, $this->wrk, $this->wrkFs,
            $this->baseRelease,
            isset($this->distributionFiles) ? $this->distributionFiles : null,
            $this->fs, $this->config, $this->httpClient
        );
        if ($this->baseRelease)
        {
            $installer->installPkgBase($output, $verboseOutput, $arch);
        }
        else
        {
            $installer->installTarBalls($output, $verboseOutput);
        }

        $installer->installPKGs($output, $verboseOutput, $arch);

        $this->prune($output, $verboseOutput);

        Misc::tarStream($this->filesDir, $this->root, $verboseOutput);
        $output->writeln(self::CHECK . ' copied overlay directory to the image');

        $this->prepare($output, $verboseOutput, $quick, $platform);

        if ($this->config->backup())
        {
            $this->backup($output, $verboseOutput);
        }

        if ($this->config->isBusyBox())
        {
            $this->busyBoxify($output, $verboseOutput);
        }

        $this->finalizeRoot($output, $verboseOutput);

        $this->buildImage($output, $verboseOutput, $quick, $platform);

        $cwd = getcwd();

        $output->writeln(sprintf(
            self::CHECK . " %s <info>size %sm</>, generated in %d seconds",
            substr($file = $this->wrk . '/tarbsd.img', strlen($cwd) + 1),
            Misc::getFileSizeM($file),
            time() - $start
        ));

        $this->dispatcher->removeSubscriber($this);

        return new SplFileInfo($file);
    }

    final public static function getSubscribedEvents() : array
    {
        return [
            ConsoleEvents::SIGNAL   => 'handleSignal',
        ];
    }

    final public function handleSignal(ConsoleSignalEvent $event) : void
    {
        switch($event->getHandlingSignal())
        {
            case \SIGINT:
            case \SIGTERM:
                $output = $event->getOutput();

                $output->writeln(sprintf(
                    "\n%s received %s signal, cleaning things up...",
                    self::ERR,
                    SignalMap::getSignalName($event->getHandlingSignal())
                ));

                $df = Process::fromShellCommandline(
                    'df -t tmpfs,nullfs --libxo=json'
                );
                $df = json_decode($df->mustRun()->getOutput(), true);

                $mounts = false;

                foreach($df['storage-system-information']['filesystem'] as $fs)
                {
                    if (str_starts_with($fs['mounted-on'], $this->wrk))
                    {
                        $mounts = true;
                        try
                        {
                            Process::fromShellCommandline(sprintf(
                                'umount -f %s',
                                $fs['mounted-on']
                            ))->mustRun();
                            $output->writeln(sprintf(
                                '%s umounted %s',
                                self::CHECK,
                                $fs['mounted-on']
                            ));
                        }
                        catch (\Exception $e)
                        {
                            $output->writeln(sprintf(
                                '%s failed to umount %s',
                                self::ERR,
                                $fs['mounted-on']
                            ));
                        }
                    }
                }

                if (!$mounts)
                {
                    $output->writeln(self::CHECK . ' no temporary mounts');
                }

                $f = (new Finder)
                    ->in($this->wrk)
                    ->name(['*.img', 'boot', 'efi'])
                    ->depth(0);

                $this->fs->remove($f);

                if ($this->md)
                {
                    Misc::mdDestroy($this->md);
                }

                $output->writeln(self::CHECK . ' rm\'d temporary files');
                break;
        }
    }

    final protected function prune(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $pruneList = [];

        // some tools use this to determine OS version
        $paramH = file_get_contents($paramHFile = $this->root . '/usr/include/sys/param.h');
        // poudriere needs this
        $mountH = file_get_contents($mountHFile = $this->root . '/usr/include/sys/mount.h');

        $readPruneList = function(string $file) use (&$pruneList)
        {
            foreach(explode("\n", file_get_contents($file)) as $line)
            {
                if (strlen($line) > 0 && $line[0] !== '#')
                {
                    $pruneList[] = $line;
                }
            }
        };

        $readPruneList(TARBSD_STUBS . '/prunelist');

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
                $readPruneList(TARBSD_STUBS . '/prunelist.openssh');
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

        foreach([
            $this->root . '/usr/share/locale',
            $this->root . '/usr/local/share/locale'
        ] as $localeDir) {
            if ($this->fs->exists($localeDir))
            {
                $f = (new Finder)
                    ->directories()
                    ->in($localeDir)
                    ->notName(['en_*', 'C.UTF*']);
                $this->fs->remove($f);
            }
        }

        Process::fromShellCommandline(implode("\n", $pruneList), $this->root)->mustRun();
        $this->fs->dumpFile($paramHFile, $paramH);
        $this->fs->dumpFile($mountHFile, $mountH);
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

        $fs->copy(TARBSD_STUBS . '/motd', $this->root . '/etc/motd.template', true);

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
            $pigzItem = $this->cache->getItem(
                hash_hmac_file('sha1', (string) $file, 'pigz')
            );

            if ($pigzItem->isHit())
            {
                $output->write(self::CHECK . ' ' . $file->getFilename() . '.gz (compressed using pigz) cached', true);
                file_put_contents($file . '.gz', $pigzItem->get());
                unlink((string) $file);
            }
            else
            {
                if (Misc::hasPigz() && !$quick)
                {
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start(sprintf(
                        "compressing %s using pigz-11, might take a while",
                        $file->getFilename(),
                    ));
                    Misc::pigzCompress((string) $file, 11, $progressIndicator);
                    $progressIndicator->finish($file->getFilename() . ' compressed');
                    $pigzItem->set(file_get_contents($file . '.gz'))->expiresAt($expiration);
                    $this->cache->save($pigzItem);
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
                    Misc::zlibCompress((string) $file, 9, $progressIndicator);
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
        $progressIndicator->start('busyboxifying');
        
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
                        . '|login|su|certctl|openssl|makefs|truncate'
                        . '|(?:[a-z]+(pass|user))'
                    . ')/', $name)
                ) {
                    $path = $this->root . '/usr/' . $dir . '/' . $name;
                    $this->fs->remove($path);
                    if (isset($bysyBoxCMDs[$name]))
                    {
                        $this->fs->symlink('../../bin/busybox', $path);
                        $progressIndicator->advance();
                    }
                }
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
                        . 'sh|expr|ln|dd'
                    . ')/', $name = $bin->getFileName())
                ) {
                    if (isset($bysyBoxCMDs[$name]))
                    {
                        $this->fs->remove($path = $this->root . '/bin/' . $name);
                        $this->fs->symlink('busybox', $path);
                        $progressIndicator->advance();
                    }
                }
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
                        $this->fs->remove($path = $this->root . '/sbin/' . $name);
                        $this->fs->symlink('../bin/busybox', $path);
                        $progressIndicator->advance();
                    }
                }
            }
        }
        $progressIndicator->finish('busyboxified');
    }

    private function backup(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $dir = $this->config->getDir();
        $backupFile = $this->root . '/root/tarbsdBackup.tar';

        $tarOptions = Misc::encodeTarOptions([
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
