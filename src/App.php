<?php declare(strict_types=1);
namespace TarBSD;

use Symfony\Component\Cache\Adapter\AdapterInterface as CacheAdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter as FilesystemCache;
use Symfony\Component\Console\Command\HelpCommand as SymfonyHelpCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Finder\Finder;

use TarBSD\Process\Forkable;

use DateTimeImmutable;
use Phar;

class App extends Application implements EventSubscriberInterface
{
    use Forkable;

    const CACHE_DIR = '/var/cache/tarbsd';

    private readonly CacheAdapterInterface $cache;

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

        $commandName = $event->getCommand()->getName();

        if (
            static::amIRoot()
            && TARBSD_SELF_UPDATE
            && $commandName !== 'self-update'
        ) {
            $item = $this->getVersionCheckItem();
            if (!$item->isHit() || $item->get() !== true)
            {
                $this->fork(
                    'updateWorker', false, false,
                    $this->getCache(), $item, $this->getHttpClient()
                );
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

    public function getCache() : CacheAdapterInterface
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

    protected function getDefaultInputDefinition() : InputDefinition
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--ansi', '', InputOption::VALUE_NEGATABLE, 'Force (or disable --no-ansi) ANSI output', null),
        ]);
    }

    protected function configureIO(InputInterface $input, OutputInterface $output) : void
    {
        if ($input->hasParameterOption(['--ansi'], true))
        {
            $output->setDecorated(true);
        }
        elseif ($input->hasParameterOption(['--no-ansi'], true))
        {
            $output->setDecorated(false);
        }
        if (
            $input->hasParameterOption('-v', true)
            || $input->hasParameterOption('--verbose', true)
            || $input->getParameterOption('--verbose', false, true)
        ) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    protected function updateWorker(CacheAdapterInterface $cache, CacheItem $item, HttpClientInterface $client) : void
    {
        try
        {
            if (is_array(Util\UpdateUtil::getLatest($client, false)))
            {
                $item->set(true)->expiresAt(new DateTimeImmutable('+1 year'));
            }
            else
            {
                $item->set(false)->expiresAt(new DateTimeImmutable('+3 hours'));
            }
            $cache->save($item);
        }
        catch (\Throwable $e)
        {}
    }
}
