<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Util\SignatureChecker;
use TarBSD\Builder;
use TarBSD\App;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use Symfony\Contracts\HttpClient\HttpClientInterface;

use DateTimeImmutable;
use Phar;

#[AsCommand(
    name: 'self-update',
    description: 'Update tarBSD builder to the latest version',
)]
class SelfUpdate extends AbstractCommand
{
    const REPO = 'pavetheway91/tarbsd';

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
    ) : int
    {
        if (!TARBSD_SELF_UPDATE)
        {
            $output->writeln(sprintf(
                "%s self-update command is only available on GitHub release version of tarBSD builder",
                self::ERR
            ));
            return self::FAILURE;
        }

        $self = Phar::running(false);

        if (!is_writable($self))
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

        $latest = $this->getLatest(
            $client = $this->getApplication()->getHttpClient(),
            App::getBuildDate(),
            hash_file('sha256', $self),
            $preRelease
        );

        if ($latest)
        {
            [$releaseName, $phar, $size, $sig] = $latest;

            $helper = $this->getHelper('question');

            $question = new Question(sprintf(
                "   There <g>is</> a new version available, you might\n   want to check what has changed first"
                . "\n   %s\n   Proceed?",
                'https://github.com/' . self::REPO . '/blob/main/CHANGELOG.md'
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
                $phar, $size, $sig, $self, $perms
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
        string $self,
        int $perms
    ) : int
    {
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
                    intval(($chunk->getOffset() / 205880752) * 100)
                );
                fwrite($handle, $chunk->getContent());
            }
        }

        $progressBar->finish();
        fclose($handle);

        if (!SignatureChecker::validateEC(
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
        $this->loadAllClasses();
        $output->writeln(self::CHECK . ' making sure nothing ugly happens during the update');

        $fs->rename($tmpFile, $self, true);

        $output->writeln(sprintf(
            self::CHECK . " tarBSD builder was updated to %s",
            $releaseName
        ));

        return self::SUCCESS;
    }

    protected function getLatest(
        HttpClientInterface $client,
        DateTimeImmutable $currentBuildDate,
        string $currentSHA256,
        bool $preRelease
    ) : ?array {
        $res = $client->request(
            'GET',
            TARBSD_GITHUB_API . '/repos/' . self::REPO . '/releases?per_page=20',
            [
                'headers' => [
                    'accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28'
                ],
                'timeout' => 5
            ]
        );

        if (($code = $res->getStatusCode()) !== 200)
        {
            throw new \Exception('api.github.com responded with: ' . $code);
        }

        $payload = json_decode($res->getContent(), true);

        foreach($payload as $release)
        {
            $pub = new DateTimeImmutable($release['created_at']);
            $name = $release['name'];

            // some wiggle room between a tag time and a build time
            $currentBuildDate = $currentBuildDate->modify('-1 hour');

            if (
                $pub > $currentBuildDate
                && (!$release['prerelease'] || $preRelease)
            ) {
                $phar = $size = $sig = $sha256 = null;

                foreach($release['assets'] as $asset)
                {
                    if (
                        $asset['name'] === 'tarbsd'
                        && preg_match('/sha256:([a-z0-9]{64})/', $asset['digest'], $m)
                    ) {
                        $phar = $asset['browser_download_url'];
                        $size = $asset['size'];
                        $sha256 = $m[1];
                    }
                    if ($asset['name'] === 'tarbsd.sig.secp384r1')
                    {
                        $sig = $asset['browser_download_url'];
                    }
                }

                if ($phar && $sig && $sha256 !== $currentSHA256)
                {
                    return [
                        $name,
                        $phar,
                        $size,
                        $sig
                    ];
                }
            }
        }
        return null;
    }

    protected function loadAllClasses() : void
    {
        $classLoader = \TarBSD\getClassLoader();

        foreach($classLoader->getPrefixesPsr4() as $ns => $dirs)
        {
            if (!preg_match('/Polyfill/', $ns))
            {
                foreach((new Finder)->files()->in($dirs)->name('*.php') as $file)
                {
                    $relativeName = $file->getRelativePathName();

                    if (ctype_upper($relativeName[0]))
                    {
                        $className = $ns . preg_replace(
                            '/\//',
                            '\\',
                            substr($relativeName, 0, strlen($relativeName) - 4)
                        );

                        try
                        {
                            @class_exists($className);
                        }
                        catch(\Throwable $e)
                        {
                        }
                    }
                }
            }
        }
    }
}
