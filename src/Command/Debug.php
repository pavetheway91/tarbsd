<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;

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
    ) {
        touch($file = '/tmp/tarbsd.debug');
        chmod($file, 0666);
        $output->writeln(sprintf(
            "%s debug mode enabled for an hour",
            self::CHECK,
        ));
        return self::SUCCESS;
    }
}
