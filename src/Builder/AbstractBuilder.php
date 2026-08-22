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
use TarBSD\GlobalConfiguration;
use TarBSD\Configuration;
use TarBSD\Util\Overlay;
use TarBSD\Util\Icons;
use TarBSD\Util\Fstab;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
use TarBSD\Util\Strs;
use TarBSD\App;

use DateTimeImmutable;
use SysvMessageQueue;
use SplFileInfo;
use Phar;

abstract class AbstractBuilder implements EventSubscriberInterface, Icons
{
    use Utils;

    const MSG_TYPE_WRKFS = 1;

    public readonly WrkFs $wrkFs;

    public readonly string $wrk;

    protected readonly string $root;

    protected readonly string $filesDir;

    protected bool $bootPruned;

    protected ?array $modules;

    public ?string $md = null;

    private string $runId;

    protected readonly Filesystem $fs;

    private readonly string $distributionFiles;

    protected Process $wrkFsSizeWorker;

    private SysvMessageQueue $sysvMessageQueue;

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
        protected readonly Configuration $config,
        protected readonly GlobalConfiguration $globalConfig,
        private readonly CacheInterface $cache,
        private readonly FreeBSDRelease $release,
        private readonly EventDispatcher $dispatcher,
        private readonly HttpClientInterface $httpClient
    ) {
        $this->wrk = $config->getDir() . '/wrk';
        $this->root = $this->wrk . '/root';
        $this->filesDir = $config->getDir() . '/tarbsd';

        $this->fs = new Filesystem;

        if (!$this->fs->exists($this->filesDir))
        {
            throw new \Exception(sprintf(
                "%s directory does not exist",
                $this->filesDir
            ));
        }
    }

    final public function build(OutputInterface $output, OutputInterface $verboseOutput, ?bool $quick, bool $preservePkgDb) : SplFileInfo
    {
        try
        {
            $this->sysvMessageQueue = Misc::newSysvMessageQueue($this->config->getDir(), $ftok);
        }
        catch (\TypeError $e)
        {
            throw new \Exception(sprintf(
                "tarBSD builder already running in %s",
                $this->config->getDir()
            ));
        }

        if (null === $quick)
        {
            if (Misc::nCPU() < 4)
            {
                $quick = true;
                $output->writeln(self::NOTE . ' system has fewer than 4 processors, defaulting to quick mode');
            }
            elseif (Misc::percentLoadAvg() >= 0.5)
            {
                $quick = true;
                $output->writeln(self::NOTE . ' system is busy, defaulting to quick mode');
            }
            else
            {
                $quick = false;
            }
        }

        $this->wrkFs = WrkFs::get($this->globalConfig, $this->config->getDir(), true, $wasCreated);

        $output->writeln(sprintf(
            self::CHECK . ' %s: (%s) %s',
            $this->config->getDir() . '/wrk',
            $this->wrkFs::TYPE,
            $wasCreated ? 'created' : 'exists'
        ));

        $this->wrkFs->start();

        $this->dispatcher->addSubscriber($this);

        msg_send($this->sysvMessageQueue, self::MSG_TYPE_WRKFS, $key = bin2hex(random_bytes(8)), false);
        $this->wrkFsSizeWorker = Process::fromShellCommandline(sprintf(
            "%s %s wrkfssize %s %s",
            PHP_BINARY,
            realpath($_SERVER['SCRIPT_FILENAME']),
            $ftok, $key
        ), $this->config->getDir(), null, null, 7200);
        register_shutdown_function(function()
        {
            $this->wrkFsSizeWorker->stop();
        });

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

        // fix file perms if they were generated using a previous version
        // of the builder
        foreach(['etc/fstab', 'etc/rc.conf', 'etc/resolv.conf', 'boot/loader.conf'] as $file)
        {
            if ($this->fs->exists($file = $this->config->getDir() . '/tarbsd/' . $file))
            {
                $this->fs->chmod($file, 0644);
            }
        }

        $installer = new Installer(
            $this->root, $this->wrk, $this->wrkFs,
            $this->release, $this->fs, $this->config,
            $this->httpClient
        );

        $installer->installPkgBase($output, $verboseOutput, $arch, $this->wrkFsSizeWorker);

        $installer->installPKGs($output, $verboseOutput, $arch);

        Misc::tarStream($this->filesDir, $this->root, $verboseOutput);
        $output->writeln(self::CHECK . ' copied overlay directory to the image');

        $this->prepare($output, $verboseOutput, $quick, $platform);

        $this->prune($output, $verboseOutput, $preservePkgDb);

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
        $n = 0;
        $output = $event->getOutput();
        switch($event->getHandlingSignal())
        {
            case \SIGTERM:
                while (msg_receive($this->sysvMessageQueue, self::MSG_TYPE_WRKFS, $type, 1024, $msg, false, MSG_IPC_NOWAIT))
                {
                    $output->writeln(sprintf(
                        "%s%s %s",
                        $n == 0 ? "\n" : "",
                        self::ERR,
                        $msg
                    ));
                    $n++;
                }
            default:
                if ($n == 0)
                {
                    $output->writeln(sprintf(
                        "\n%s received %s signal, cleaning things up...",
                        self::ERR,
                        SignalMap::getSignalName($event->getHandlingSignal())
                    ));
                }

                $mounts = false;

                foreach(Misc::df(['tmpfs,nullfs'], $this->wrk, true) as $fs)
                {
                    if ($fs['mnt'] !== $this->wrk)
                    {
                        $mounts = true;
                        try
                        {
                            Process::fromShellCommandline(sprintf(
                                'umount -f %s',
                                $fs['mnt']
                            ))->mustRun();
                            $output->writeln(sprintf(
                                '%s unmounted %s',
                                self::CHECK,
                                $fs['mnt']
                            ));
                        }
                        catch (\Exception $e)
                        {
                            $output->writeln(sprintf(
                                '%s failed to unmount %s',
                                self::ERR,
                                $fs['mnt']
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

    final protected function prune(OutputInterface $output, OutputInterface $verboseOutput, bool $preservePkgDb) : void
    {
        switch($this->config->getSSH())
        {
            case 'dropbear':
            case null:
                $this->applyPruneList(Strs::PRUNELIST_OPENSSH);
                break;
            case 'openssh':
                break;
            default:
                throw new \Exception(sprintf(
                    'unknown SSH client %s, valid values are dropbear, openssh and null',
                    $this->config->getSSH()
                ));
        }

        foreach($this->config->features() as $feature)
        {
            if (!$feature->isEnabled())
            {
                $feature->prune($this->root, $this->fs);
            }
        }
        if ($preservePkgDb)
        {
            $this->fs->remove($this->root . '/var/db/pkg/');
        }
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
            TARBSD_LICENSE
        ));

        foreach(Overlay::RC_FILES as $name => $file)
        {
            $fs->dumpFile($outFile = $this->root . '/etc/rc.d/' . $name, $file);
            $fs->chmod($outFile, 0555);
        }

        $fs->dumpFile($this->root . '/etc/motd.template', Strs::MOTD);

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
            $fileName = $file->getFilename();
            $file = (string) $file;

            $zlibItem = $this->cache->getItem(hash_hmac_file('sha1', $file, 'zlib'));
            $pigzItem = $this->cache->getItem(hash_hmac_file('sha1', $file, 'pigz'));
            $libdefateItem = $this->cache->getItem(hash_hmac_file('sha1', $file, 'libdeflate'));

            if ($pigzItem->isHit())
            {
                $output->write(self::CHECK . ' ' . $fileName . '.gz (compressed using pigz) cached', true);
                file_put_contents($file . '.gz', $pigzItem->get());
                unlink($file);
            }
            elseif ($libdefateItem->isHit())
            {
                $output->write(self::CHECK . ' ' . $fileName . '.gz (compressed using libdefate) cached', true);
                file_put_contents($file . '.gz', $libdefateItem->get());
                unlink($file);
            }
            else
            {
                if (extension_loaded('libdeflate'))
                {
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start(sprintf(
                        "compressing %s using libdeflate",
                        $fileName,
                    ));
                    if ($compressed = libdeflate_gzip_compress(file_get_contents($file), 12))
                    {
                        unlink($file);
                        $progressIndicator->finish($fileName . ' compressed using libdeflate');
                        file_put_contents($file . '.gz', $compressed);
                        $libdefateItem->set($compressed)->expiresAt($expiration);
                        $this->cache->save($libdefateItem);
                    }
                    else
                    {
                        throw new \Exception('libdeflate compression failed');
                    }
                }
                elseif (Misc::hasPigz() && !$quick)
                {
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start(sprintf(
                        "compressing %s using pigz-11, might take a while",
                        $fileName,
                    ));
                    Misc::pigzCompress($file, 11, $progressIndicator);
                    $progressIndicator->finish($fileName . ' compressed using pigz');
                    $pigzItem->set(file_get_contents($file . '.gz'))->expiresAt($expiration);
                    $this->cache->save($pigzItem);
                }
                elseif ($zlibItem->isHit())
                {
                    $output->write(self::CHECK . ' ' . $fileName . '.gz (compressed using zlib) cached', true);
                    file_put_contents($file . '.gz', $zlibItem->get());
                    unlink($file);
                }
                else
                {
                    $progressIndicator = $this->progressIndicator($output);
                    $progressIndicator->start(sprintf(
                        "compressing %s",
                        $fileName,
                    ));
                    Misc::zlibCompress($file, 9, $progressIndicator);
                    $progressIndicator->finish($fileName . ' compressed using zlib');
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

        $bysyBoxCMDs = explode("\n", Strs::BUSYBOX);
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
                        . '|login|su|certctl|openssl|makefs|truncate|swapinfo'
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
