<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Filesystem\Filesystem;

use TarBSD\App;

#[AsCommand(
    name: 'purge-cache',
    description: 'Purge caches from /var/cache/tarbsd'
)]
class PurgeCache extends AbstractCommand
{
    public function __invoke(InputInterface $input, OutputInterface $output) : int
    {
        $helper = new QuestionHelper();
        $question = new ChoiceQuestion(
            'Would you like to purge?',
            ['everything', 'compression cache', 'base packages'],
            0
        );

        $question->setErrorMessage('Please answer with 0-2');
        $fs = new Filesystem;
        $compressCache = $this->getApplication()->getCache();

        switch($purge = $helper->ask($input, $output, $question))
        {
            case 'everything':
            case 'compression cache':
                $compressCache->clear();
                $output->writeln(self::CHECK . ' purged compression cache');
                if ($purge == 'compression cache')
                {
                    break;
                }
            case 'base packages':
                $fs->remove([
                    App::CACHE_DIR . '/pkgbase_amd64',
                    App::CACHE_DIR . '/pkgbase_aarch64'
                ]);
                $output->writeln(self::CHECK . ' purged package cache');
                break;
        }

        return self::SUCCESS;
    }
}
