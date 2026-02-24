<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Filesystem\Filesystem;

use TarBSD\Util\UpdateUtil;
use TarBSD\App;

use DateTimeImmutable;
use Phar;

#[AsCommand(
    name: 'self-update',
    description: 'Update tarBSD builder to the latest version',
)]
class SelfUpdate extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct();

        if (!TARBSD_SELF_UPDATE)
        {
            $this->setHidden(true);
        }
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('Accept pre-release')] bool $preRelease = false
    ) : int {

        if (!TARBSD_SELF_UPDATE)
        {
            $output->writeln(sprintf(
                "%s self-update command is only available on GitHub release version of tarBSD builder",
                self::ERR
            ));
            return self::FAILURE;
        }

        if (!is_writable($self = Phar::running(false)))
        {
            $output->writeln(sprintf(
                "%s  %s is not writable",
                self::ERR,
                $self
            ));
            return self::FAILURE;
        }

        if (!is_int($perms = fileperms($self)))
        {
            $output->writeln(sprintf(
                '%s  There was an unexplainable error, tarbsd builder'
                . ' wasn\'t able to figure it\'s own file permissions',
                self::ERR,
            ));
            return self::FAILURE;
        }

        $client = $this->getApplication()->getHttpClient();

        if ([$releaseName, $phar, $size, $sig] = UpdateUtil::getLatest($client, $preRelease))
        {
            $helper = $this->getHelper('question');

            $question = new Question(sprintf(
                "   There <g>is</> a new version available, you might\n   want to check what has changed first"
                . "\n   %s\n   Proceed?",
                'https://github.com/' . UpdateUtil::REPO . '/blob/main/CHANGELOG.md'
            ));
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

            if (false == $helper->ask($input, $section = $output->section(), $question))
            {
                $output->writeln(self::CHECK . ' stopping update');
                return self::SUCCESS;
            }
            $section->clear();

            return $this->runUpdate(
                $client, $output, $releaseName,
                $phar, $size, $sig, $perms
            );
        }
        else
        {
            $output->writeln(self::CHECK . ' you are already using the latest version');
        }

        return self::SUCCESS;
    }

    protected function runUpdate(
        HttpClientInterface $client,
        OutputInterface $output,
        string $releaseName,
        string $phar,
        int $size,
        string $sig,
        int $perms
    ) : int {

        $tmpFile = '/tmp/' . 'tarbsd_update_' . bin2hex(random_bytes(8));

        if (!is_resource($handle = fopen($tmpFile, 'w')))
        {
            throw new \Exception(sprintf(
                "could not open %s",
                $tmpFile
            ));
        }

        $res = [
            'phar'  => $client->request('GET', $phar),
            'sig'   => $client->request('GET', $sig),
        ];

        $progressBar = new ProgressBar($output, 100);
        ProgressBar::setPlaceholderFormatterDefinition(
            'state',
            function (ProgressBar $progressBar, OutputInterface $output) : string
            {
                return $progressBar->getProgress() < 100 ? ' downloading' : self::CHECK . ' downloaded';
            }
        );
        $progressBar->setFormat("%state% [%bar%] %percent% % \n");

        foreach ($client->stream($res) as $response => $chunk)
        {
            if ($response->getInfo()['original_url'] === $phar)
            {
                if ($chunk->isFirst())
                {
                    $progressBar->start();
                }
                $progressBar->advance(
                    intval(($chunk->getOffset() / $size) * 100)
                );
                fwrite($handle, $chunk->getContent());
            }
        }

        $progressBar->finish();
        fclose($handle);

        if (!UpdateUtil::validateEC(
            $tmpFile,
            $res['sig']->getContent()
        )) {
            throw new \Exception('Signature didn\'t match!');
        }
        $output->writeln(self::CHECK . ' signature ok');

        $fs = new Filesystem;

        $fs->chmod($tmpFile, $perms);
        $output->writeln(self::CHECK . ' file permissions');

        /**
         * To make sure that there aren't any
         * ugly read errors, we'll load
         * every class, interface and trait
         * in the current phar archive before
         * it gets overriden.
         */
        $this->getApplication()->classLoader->loadAllClasses();

        $fs->rename($tmpFile, Phar::running(false), true);

        $output->writeln(sprintf(
            self::CHECK . " tarBSD builder was updated to %s",
            $releaseName
        ));

        return self::SUCCESS;
    }
}
