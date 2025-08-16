<?php declare(strict_types=1);
namespace TarBSD;


use Symfony\Component\Cache\Adapter\FilesystemAdapter as FilesystemCache;
use Symfony\Component\Console\Command\HelpCommand as SymfonyHelpCommand;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Application;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;

use Symfony\Component\Process\Process;

use DateTimeImmutable;

class App extends Application implements EventSubscriberInterface
{
    private readonly FilesystemCache $cache;

    private readonly EventDispatcher $dispatcher;

    private readonly HttpClientInterface $httpClient;

    public function __construct()
    {
        Util\PlatformCheck::run();

        parent::__construct('', TARBSD_VERSION ?: 'dev');

        $this->setDispatcher(
            $this->dispatcher = new EventDispatcher
        );

        $this->dispatcher->addSubscriber($this);
    }

    public static function getBuildDate() : ?DateTimeImmutable
    {
        static $date;

        if (TARBSD_BUILD_ID && null === $date)
        {
            $date = DateTimeImmutable::createFromFormat(
                'U',
                (string) uuid_time(TARBSD_BUILD_ID)
            );
        }

        return $date;
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

        if (
            !in_array($name = $event->getCommand()->getName(), ['list', 'help', 'diagnose'])
            && !static::amIRoot()
        ){
            $output->writeln(sprintf(
                "%s tarBSD builder needs root privileges for %s command",
                Command\AbstractCommand::ERR,
                $event->getCommand()->getName()
            ));
            $event->disableCommand();
        }
    }

    public function terminateEvent(ConsoleTerminateEvent $event) : void
    {
        if (42 == random_int(0, 49))
        {
            $this->getCache()->prune();
        }
    }

    public function getCache() : FilesystemCache
    {
        if (!isset($this->cache))
        {
            $this->cache = new FilesystemCache(
                '',
                0,
                '/var/cache/tarbsd'
            );
        }
        return $this->cache;
    }

    public function getHttpClient() : HttpClientInterface
    {
        if (!isset($this->httpClient))
        {
            $this->httpClient = HttpClient::create();
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
            /**
             * todo: replace this with own help
             * command, which doesn't make empty
             * promises about unimplemented features
             * or confuse the user with too many
             * options that aren't even in anyone's
             * interest.
             */
            new SymfonyHelpCommand,
            new Command\Build,
            new Command\Bootstrap,
            new Command\SelfUpdate,
            new Command\Diagnose,
            new Command\SelfCheckSig
        ];
    }
}
