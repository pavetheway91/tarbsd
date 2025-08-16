<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Util\WrkFs;
use TarBSD\App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'wrk-destroy',
    description: 'Destroy the wrk filesystem'
)]
class WrkDestroy extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output
    ) {
        if ($fs = WrkFs::get($cwd = getcwd()))
        {
            $fs->destroy();
            $output->writeln(sprintf(
                "%s %s destroyed",
                self::CHECK,
                $fs->mnt
            ));
            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            "%s  could not find wrk filesystem from %s",
            self::ERR,
            realpath($cwd)
        ));

        return self::FAILURE;
    }
}
