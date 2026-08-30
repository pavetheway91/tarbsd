<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

use TarBSD\Process\IPC\SemaphoreAcquireException;
use TarBSD\Builder\AbstractBuilder;
use TarBSD\GlobalConfiguration;
use TarBSD\Process\IPC;
use TarBSD\Util\WrkFs;
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
            $q = IPC::getMessageQueue($cwd = getcwd(), AbstractBuilder::SEMAPHORE_ID);
        }
        catch (SemaphoreAcquireException $e)
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
                $q->release();
                $output->writeln(sprintf(
                    "%s %s destroyed",
                    self::CHECK,
                    $fs->mnt
                ));
                return self::SUCCESS;
            }
            catch(\Exception $e)
            {
                $q->release();
                throw $e;
            }
        }

        $q->release();

        $output->writeln(sprintf(
            "%s  could not find wrk filesystem from %s",
            self::ERR,
            realpath($cwd)
        ));

        return self::FAILURE;
    }
}
