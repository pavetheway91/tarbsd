<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
use TarBSD\App;

#[AsCommand(
    name: 'wrk-destroy',
    description: 'Destroy the wrk filesystem'
)]
class WrkDestroy extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output
    ) {
        try
        {
            Misc::newSysvMessageQueue($cwd = getcwd());
        }
        catch (\TypeError $e)
        {
            throw new \Exception(sprintf(
                "tarBSD builder already running in %s",
                $cwd
            ));
        }

        if ($fs = WrkFs::get($cwd))
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
