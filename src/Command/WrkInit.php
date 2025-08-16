<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Util\WrkFs;
use TarBSD\App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\Argument;

#[AsCommand(
    name: 'wrk-init',
    description: 'Initialize the wrk filesystem with a custom size'
)]
class WrkInit extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Argument('Size in gigs')] int $size,
    ) {
        if ($fs = WrkFs::get($cwd = getcwd()))
        {
            $output->writeln(sprintf(
                "%s  %s exists already",
                self::ERR,
                $fs->mnt
            ));
            return self::FAILURE;
        }

        WrkFs::init($cwd, $size);

        $output->writeln(sprintf(
            "%s  %s created",
            self::CHECK,
            realpath($cwd) . '/wrk'
        ));

        return self::SUCCESS;
    }
}
