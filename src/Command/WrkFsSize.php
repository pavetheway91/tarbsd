<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

use TarBSD\Util\WrkFs;
use TarBSD\App;

#[AsCommand(
    name: 'wrkfssize',
    hidden: true
)]
class WrkFsSize extends AbstractCommand
{
    public function __invoke(OutputInterface $output) : int
    {
        if (App::amIRoot())
        {
            $wrkFs = WrkFs::get(getcwd());
            while(true)
            {
                $wrkFs->checkSize();
                usleep(250000);
            }
        }
    }
}
