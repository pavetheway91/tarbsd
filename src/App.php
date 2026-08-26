<?php declare(strict_types=1);
namespace TarBSD;

use Symfony\Component\Cache\Adapter\FilesystemAdapter as FilesystemCache;
use Symfony\Component\Console\Command\HelpCommand as SymfonyHelpCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Finder\Finder;

use DateTimeImmutable;
use Phar;

class App extends Application implements EventSubscriberInterface
{
    const CACHE_DIR = '/var/cache/tarbsd';

    private readonly FilesystemCache $cache;

    private readonly EventDispatcher $dispatcher;

    private readonly HttpClientInterface $httpClient;

    public function __construct()
    {
        if (!defined('TARBSD_TEST'))
        {
            Util\Misc::platformCheck();
        }

        parent::__construct('', TARBSD_VERSION ?: 'dev');

        $this->setDispatcher(
            $this->dispatcher = new EventDispatcher
        );

        $this->dispatcher->addSubscriber($this);
    }

    public static function getReleaseDate() : ?DateTimeImmutable
    {
        static $date;

        if (TARBSD_VERSION && null === $date)
        {
            if (preg_match('/(([0-9]{2})\.([0-9]{2})\.([0-9]{2}))/', TARBSD_VERSION, $m))
            {
                return  $date = DateTimeImmutable::createFromFormat(
                    'y.m.d H:i:s e',
                    $m[1] . ' 00:00:00 UTC'
                );
            }
            throw new \Exception('failed to parse tarBSD version ' . $v);
        }

        return $date;
    }

    public static function hashPhar() : ?string
    {
        if ($phar = Phar::running(false))
        {
            return hash_file('sha256', $phar);
        }
        return null;
    }

    public function commandEvent(ConsoleCommandEvent $event) : void
    {
        $output = $event->getOutput();

        foreach(['red', 'green', 'blue'] as $colour)
        {
            $output->getFormatter()->setStyle(
                $colour[0],
                new OutputFormatterStyle($colour)
            );
        }

        $command = $event->getCommand();
        $commandName = $event->getCommand()->getName();

        if (
            static::amIRoot()
            && TARBSD_SELF_UPDATE
            && $commandName !== 'self-update'
        ) {
            $cache = $this->getCache();
            $item = $cache->getItem(
                hash_hmac('sha256', 'version_check', self::hashPhar())
            );
            if (!$item->isHit())
            {
                $this->checkForUpdate();
                $item->set(true)->expiresAt(new DateTimeImmutable('+3 hours'));
                $cache->save($item);
            }
        }

        if (
            !in_array($commandName, ['list', 'help', 'diagnose', 'debug'])
            && !static::amIRoot()
        ) {
            $output->writeln(sprintf(
                "%s tarBSD builder needs root privileges for %s command",
                Command\AbstractCommand::ERR,
                $commandName
            ));
            $event->disableCommand();
        }
    }

    public function terminateEvent(ConsoleTerminateEvent $event) : void
    {
        if (self::amIRoot())
        {
            $cache = $this->getCache();

            if (42 == random_int(29, 49))
            {
                $cache->prune();
            }
            else
            {
                $item = $cache->getItem('pkgbase_prune');
                if (!$item->isHit())
                {
                    $fs = new Filesystem;
                    foreach(['pkgbase', 'pkgbase_amd64', 'pkgbase_aarch64'] as $dir)
                    {
                        if ($fs->exists($pkgCache = self::CACHE_DIR . '/' . $dir))
                        {
                            $f = (new Finder)
                                ->files()
                                ->in($pkgCache)
                                ->date('until 90 days ago');
                            $fs->remove($f);

                            $f = (new Finder)
                                ->files()
                                ->in($pkgCache)
                                ->name('*.snap*')
                                ->date('until 4 days ago');
                            $fs->remove($f);
                        }
                    }
                    $item->set(true)->expiresAt(new DateTimeImmutable('+3 days'));
                    $cache->save($item);
                }
            }
        }
    }

    public function getCache() : FilesystemCache
    {
        if (!isset($this->cache))
        {
            $this->cache = new FilesystemCache('', 0, self::CACHE_DIR);
        }
        return $this->cache;
    }

    public function getVersionCheckItem() : CacheItem
    {
        return $this->getCache()->getItem(
            hash_hmac('sha256', 'update_available', self::hashPhar())
        );
    }

    public function getHttpClient() : HttpClientInterface
    {
        if (!isset($this->httpClient))
        {
            $this->httpClient = new NativeHttpClient;
        }
        return $this->httpClient;
    }

    public function getDispatcher() : EventDispatcher
    {
        return $this->dispatcher;
    }

    public static function getSubscribedEvents() : array
    {
        return [
            ConsoleEvents::TERMINATE    => 'terminateEvent',
            ConsoleEvents::COMMAND      => 'commandEvent'
        ];
    }

    public static function amIRoot() : bool
    {
        static $amI;

        if (null === $amI)
        {
            if (extension_loaded('posix'))
            {
                $amI = posix_getuid() == 0;
            }
            else
            {
                $u = Process::fromShellCommandline(
                    'whoami'
                )->mustRun()->getOutput();
                $amI = trim($u, "\n") == 'root';
            }
        }

        return $amI;
    }

    protected function getDefaultCommands() : array
    {
        return [
            new Command\ListCmds,
            new SymfonyHelpCommand,
            new Command\Build,
            new Command\Write,
            new Command\Bootstrap,
            new Command\ChPass,
            new Command\WrkDestroy,
            new Command\SelfUpdate,
            new Command\CachePurge,
            new Command\Diagnose,
            // these are for developement purposes
            new Command\SelfCheckSig,
            new Command\Debug,
        ];
    }

    private function checkForUpdate() : void
    {
        if (TARBSD_SELF_UPDATE)
        {
            switch(pcntl_fork())
            {
                case -1:
                    throw new \Exception('fork failed');
                case 0:
                    try
                    {
                        $item = $this->getVersionCheckItem();
                        if (!$item->isHit() || $item->get() !== true)
                        {
                            if (is_array(Util\UpdateUtil::getLatest($this->getHttpClient(), false)))
                            {
                                $item->set(true)->expiresAt(new DateTimeImmutable('+1 year'));
                                $this->getCache()->save($item);
                            }
                        }
                    }
                    catch (\Throwable $e) {}
                    die;
                default:
                    return;
            }
        }
    }
}
