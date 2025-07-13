<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Util\SignatureChecker;
use TarBSD\Builder;
use TarBSD\App;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

use GuzzleHttp\Client as Guzzle;

use Composer\Autoload\ClassLoader;

use DateTimeImmutable;
use Reflectionclass;

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

        if (!file_exists(TARBSD_STUBS . '/isRelease'))
        {
            $this->setHidden(true);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        if ($currentBuildDate = App::getBuildDate())
        {
            $guzzle = new Guzzle;

            if ($latest = $this->getLatest($guzzle, $currentBuildDate))
            {    
                $helper = $this->getHelper('question');

                $question = new Question(sprintf(
                    "   There <info>is</> a new version available, you might\n   want to check what has changed first"
                    . "\n   %s\n   Proceed?",
                    'https://github.com/' . self::REPO . '/releases'
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

                $phar = $guzzle->request('GET', $latest[0], [
                    'sink' => $tmpFile = '/tmp/' . 'tarbsd_update_' . bin2hex(random_bytes(8))
                ]);
                if ($phar->getStatusCode() !== 200)
                {
                    throw new \Exception('Download failed, status code: ' . $res->getStatusCode());
                }

                $sig = $guzzle->request('GET', $latest[1]);
                if ($sig->getStatusCode() !== 200)
                {
                    throw new \Exception('Signature download failed, status code: ' . $res->getStatusCode());
                }

                if (!SignatureChecker::validateEC($tmpFile, $sig->getBody()->getContents()))
                {
                    throw new \Exception('Signature didn\'t match!');
                }
                $output->writeln(self::CHECK . ' signature ok');

                $fs = new Filesystem;
                $perms = fileperms($self = $_SERVER['SCRIPT_FILENAME']);

                if ($perms !== false)
                {
                    $fs->chmod($tmpFile, $perms);
                    $output->writeln(self::CHECK . ' checking file permissions');

                    /**
                     * To make sure that there aren't any
                     * ugly read errors, we'll load
                     * every class, interface and trait
                     * in the current phar archive before
                     * it gets overriden.
                     */
                    $this->loadAllClasses();
                    $output->writeln(self::CHECK . ' making sure nothing ugly happens during update');

                    $fs->rename($tmpFile, $self, true);

                    //$this->showLogo($output);

                    $output->writeln(sprintf(
                        self::CHECK . " tarBSD builder was updated to a version published\n   at %s",
                        $latest[2]->format('Y-m-D H:i:s \\U\\T\\C')
                    ));
                }
                else
                {
                    throw new \Exception(
                        'There was an unexplainable error, tarbsd builder'
                        . ' wasn\'t able to figure it\'s own file permissions'
                    );
                }
            }
            else
            {
                $this->showLogo($output);
                $output->writeln('You are already using the latest available version');
            }
        }

        return self::SUCCESS;
    }

    protected function getLatest(Guzzle $guzzle, DateTimeImmutable $currentBuildDate) : ?array
    {
        $res = $guzzle->request(
            'GET',
            TARBSD_GITHUB_API . '/repos/' . self::REPO . '/releases?per_page=5',
            [
                'headers' => [
                    'accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28'
                ],
                'connect_timeout' => 5
            ]
        );

        if ($res->getStatusCode() !== 200)
        {
            throw new \Exception('api.github.com responded with: ' . $res->getStatusCode());
        }

        $payload = json_decode($res->getBody()->getContents(), true);

        foreach($payload as $release)
        {
            $pub = new DateTimeImmutable($release['published_at']);

            if ($pub->modify('-1 hour') > $currentBuildDate)
            {
                $phar = null;
    
                $sig = null;

                foreach($release['assets'] as $asset)
                {
                    if ($asset['name'] === 'tarbsd')
                    {
                        $phar = $asset['browser_download_url'];
                    }
                    if ($asset['name'] === 'tarbsd.sig.secp384r1')
                    {
                        $sig = $asset['browser_download_url'];
                    }
                }
                if ($phar && $sig)
                {
                    return [
                        $phar,
                        $sig,
                        $pub
                    ];
                }
            }
        }
        return null;
    }

    protected function loadAllClasses() : void
    {
        $ref = new Reflectionclass(ClassLoader::class);
        $file = $ref->getFileName();

        $classLoader = require dirname(dirname($file)) . '/autoload.php';

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
