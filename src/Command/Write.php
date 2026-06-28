<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

use TarBSD\Builder\Utils;
use TarBSD\Util\Misc;
use TarBSD\App;

#[AsCommand(
    name: 'write',
    description: 'Write tarBSD image to a device',
)]
class Write extends AbstractCommand
{
    use Utils;

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Device')] string $device,
        #[Option('Do not ask', '', 'f')] bool $doNotAsk = false
    ) {
        if (!str_starts_with($device, '/dev/'))
        {
            $device = '/dev/' . $device;
        }

        if (!file_exists($device))
        {
            $output->writeln(sprintf(
                "%s %s does not exists",
                self::ERR,
                $device
            ));
            return self::FAILURE;
        }

        $cwd = getcwd();

        if (!file_exists($cwd . '/tarbsd.yml'))
        {
            $output->writeln(self::ERR . ' There\'s no tarBSD project here');
            return self::FAILURE;
        }

        if (!file_exists($if = $cwd . '/wrk/tarbsd.img'))
        {
            $output->writeln(self::ERR . ' Build tarbsd image first');
            return self::FAILURE;
        }

        if (!$doNotAsk && !$this->ask(sprintf(' Wipe %s (y/n)', $device), $input, $output))
        {
            $output->writeln(self::CHECK . ' aborted');
            return self::SUCCESS;
        }

        Process::fromShellCommandline(sprintf(
            "gpart destroy -F %s",
            $device,
        ))->run();

        $progressIndicator = $this->progressIndicator($output);
        $progressIndicator->start('writing image');

        Misc::dd($if, $device, $progressIndicator);

        $progressIndicator->finish(sprintf(
            'image written to %s',
            $device
        ));

        return self::SUCCESS;
    }

    protected function ask(string $question, InputInterface $input, OutputInterface $output) : bool
    {
        $helper = $this->getHelper('question');

        $question = new Question($question . " ");

        $question->setValidator(function ($value) : bool
        {
            if (
                is_string($value)
                &&
                in_array($value, ['y', 'n', 'yes', 'no', 'yep', 'nope'])
            ) {
                return $value[0] == 'y';
            }
            throw new \Exception('(y)yes or (n)no');
        });
        return $helper->ask($input, $output, $question);
    }
}
