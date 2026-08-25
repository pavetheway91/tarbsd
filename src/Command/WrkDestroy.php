<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

use TarBSD\Builder\AbstractBuilder;
use TarBSD\GlobalConfiguration;
use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
use TarBSD\App;

#[AsCommand(
    name: 'wrk-destroy',
    aliases: ['wd', 'destroy-wrk'],
    description: 'Destroy the wrk filesystem'
)]
class WrkDestroy extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output
    ) {
        try
        {
            $q = Misc::newSysvMessageQueue($cwd = getcwd(), AbstractBuilder::QUEUE_ID);
        }
        catch (\TypeError $e)
        {
            throw new \Exception(sprintf(
                "tarBSD builder already running in %s",
                $cwd
            ));
        }

        if ($fs = WrkFs::get(new GlobalConfiguration, $cwd, false))
        {
            try
            {
                $fs->destroy();
                msg_remove_queue($q);
                $output->writeln(sprintf(
                    "%s %s destroyed",
                    self::CHECK,
                    $fs->mnt
                ));
                return self::SUCCESS;
            }
            catch(\Exception $e)
            {
                msg_remove_queue($q);
                throw $e;
            }
        }

        msg_remove_queue($q);

        $output->writeln(sprintf(
            "%s  could not find wrk filesystem from %s",
            self::ERR,
            realpath($cwd)
        ));

        return self::FAILURE;
    }
}
