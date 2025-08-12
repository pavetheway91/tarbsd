<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Builder;
use TarBSD\App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'diagnose',
    description: 'Paste output of this to bug reports'
)]
class Diagnose extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output
    ) {
        $output->writeln('  uname: ' . php_uname());
        $output->writeln('  build id: ' . TARBSD_BUILD_ID);
        $output->writeln('  ports: ' . (TARBSD_PORTS ? 'yep' : 'nope'));
        $output->writeln('  gh: ' . (TARBSD_SELF_UPDATE ? 'yep' : 'nope'));
        $output->writeln('  php: ' . PHP_VERSION);
        $output->writeln('  extensions: ' . implode(',', get_loaded_extensions()));
    }
}
