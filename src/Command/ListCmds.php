<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Builder;
use TarBSD\App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'list',
    hidden: true
)]
class ListCmds extends AbstractCommand
{
    public function __invoke(OutputInterface $output) : int
    {
        $this->showLogo($output);
        $this->showVersion($output);

        $cmds = [];
        $maxCmdLen = 0;
        foreach($this->getApplication()->all() as $name => $command)
        {
            if (!$command->isHidden())
            {
                $cmds[$name] = $command->getDescription();
                if ($len = strlen($name) > $maxCmdLen)
                {
                    $maxCmdLen = $len;
                }
            }
        }

        $output->writeln('<comment> Available commands</comment>:');
        foreach($cmds as $name => $desc)
        {
            $output->writeln(sprintf(
                '   <info>%s</info>%s',
                str_pad($name, $maxCmdLen + 15, ' '),
                $desc
            ));
        }

        return self::SUCCESS;
    }
}
