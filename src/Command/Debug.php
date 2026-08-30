<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;

use TarBSD\Util\SysVMessageQueueLogger;
use TarBSD\App;

#[AsCommand(
    name: 'debug',
    description: 'Enable debug mode for an hour',
    hidden: true
)]
class Debug extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option('Show loader messages', '', 'l')] bool $loader = false
    ) {
        touch($file = '/tmp/tarbsd.debug');
        chmod($file, 0666);

        if (!extension_loaded('sysvmsg'))
        {
            $output->writeln(sprintf(
                "%s debug mode enabled for an hour, cannot listen to messages (sysvmsg needed)",
                self::CHECK,
            ));
            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            "%s debug mode enabled for an hour, listening for messages...",
            self::CHECK,
        ));

        $logger = new SysVMessageQueueLogger;

        register_shutdown_function(function() use ($output)
        {
            msg_remove_queue(SysVMessageQueueLogger::getQueue());
        });

        foreach($logger->listen() as $msg)
        {
            [$level, $message, $pid] = $msg;
            if ($level !== 'loader' || $loader)
            {
                $output->writeln(sprintf(
                    '<comment>%s</> <r>%d</r> %s',
                    $level,
                    $pid,
                    $message
                ));
            }
        }

        return self::SUCCESS;
    }
}
