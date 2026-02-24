<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Cache\Adapter\NullAdapter as NullCache;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

use TarBSD\Builder\MfsBuilder;
use TarBSD\Util\FreeBSDRelease;
use TarBSD\Util\Misc;
use TarBSD\Configuration;
use TarBSD\GlobalConfiguration;

use DateTimeImmutable;

#[AsCommand(
    name: 'build',
    description: 'Build tarBSD image'
)]
class Build extends AbstractCommand
{
    const KNOWN_FORMATS = [
        'img', 'qcow2', 'qcow', 'vdi', 'vmdk', 'vhdx', 'vpc', 'parallels'
    ];

    public function __invoke(
        OutputInterface $output,
        #[Option('Distribution files (base.txz and kernel.txz) location')] ?string $distfiles = null,
        #[Option('FreeBSD release', '', 'r')] ?string $release = null,
        #[Option('Loosen compression settings')] bool $quick = false,
        #[Argument('Output image formats')] array $formats = [],
        #[Option('Skip cache (for testing)')] bool $doNotCache = false
    ) : int {

        if (
            ($release && $distfiles)
            || (!$release && !$distfiles)
        ) {
            throw new \Exception(
                'Please provide eighter release or distfiles option (but not both)'
            );
        }

        $distFilesInput = $distfiles;
        $distFiles = null;

        if ($distFilesInput)
        {
            if (null == $distFiles = $this->findDistributionFiles($distFilesInput))
            {
                throw new \Exception(sprintf(
                    'Cannot find kernel.txz and base.txz from %s',
                    $distFilesInput
                ));
            }
        }
        else
        {
            $release = new FreeBSDRelease($release);
        }

        if (0 < count($formats))
        {
            $formats = $this->filterFormats($formats);
        }

        $cache = $doNotCache
            ? new NullCache
            : $this->getApplication()->getCache();

        $fs = new Filesystem;

        $builder = new MfsBuilder(
            $conf = Configuration::get(),
            $cache,
            $release ?: $distFiles,
            $this->getApplication()->getDispatcher(),
            $this->getApplication()->getHttpClient()
        );

        $globalConfig = $this->getApplication()->getGlobalConfig();
        $logFile = null;

        if ($output->isVerbose())
        {
            $verboseOutput = $output;
            $output = new NullOutput;
        }
        else
        {
            $this->showLogo($output);
            $this->showVersion($output);
            if ($distFilesInput)
            {
                $output->writeln(
                    MfsBuilder::ERR . " Tarball installation method has been deprecated"
                    . "and and\n   will be removed in a future release."
                );
            }
            if (!$fs->exists($logDir = $conf->getDir() . '/log'))
            {
                $fs->mkdir($logDir);
            }
            $time = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s');
            $logFile = fopen($logFilePath = $logDir . '/' . $time . '.log', 'w');
            if (!$logFile)
            {
                throw new \Exception('failed to open logfile: ' . $logFilePath);
            }
            $fs->symlink(
                $logFilePath,
                $logDir . '/latest'
            );
            $verboseOutput = new StreamOutput($logFile);
        }

        $img = $builder->build(
            $output,
            $verboseOutput,
            $quick
        );

        if ($logFile)
        {
            fclose($logFile);
            $this->logRotate($logDir, $globalConfig, $fs);
        }

        foreach($formats as $format)
        {
            $builder->wrkFs->checkSize(Misc::getFileSizeM(
                $builder->wrk . '/tarbsd.img'
            ));

            Process::fromShellCommandline(sprintf(
                "qemu-img convert -f raw -O %s %s %s",
                $format,
                $img,
                $formattedName = $img->getBasename('.img') . '.' . $format
            ), $img->getPath())->mustRun();

            $output->writeln(MfsBuilder::CHECK . ' wrk/' . $formattedName . ' generated');
        }

        $this->showUpdateMessage($output);

        return self::SUCCESS;
    }

    protected function findDistributionFiles(string $dir) : string|null
    {
        $dir = realpath($dir);

        foreach(['/', '/usr/freebsd-dist/'] as $dir2)
        {
            if (
                file_exists($dir . $dir2 . '/base.txz')
                && file_exists($dir . $dir2 . '/kernel.txz')
            ) {
                return $dir . $dir2;
            }
        }
        return null;
    }

    protected function filterFormats(array $formats) : array
    {
        try
        {
            Process::fromShellCommandline('which qemu-img')->mustRun();
        }
        catch(\Exception $e)
        {
            throw new \Exception(
                "please install qemu or qemu-tools package to use random image formats"
            );
        }
        $notfound = [];
        foreach($formats as $index => $format)
        {
            if (false === array_search($format, self::KNOWN_FORMATS))
            {
                $notfound[] = $format;
            }
            if ($format == 'img')
            {
                unset($formats[$index]);
            }
        }
        if ($notfound)
        {
            throw new \Exception(sprintf(
                'unknown image format%s: %s',
                count($notfound) > 1 ? "s" : "",
                implode(", ", $notfound)
            ));
        }
        return $formats;
    }

    protected function logRotate(string $dir, GlobalConfiguration $config) : void
    {
        if ($config->logRotate)
        {
            $f = (new Finder)
                ->files()->in($dir)
                ->name(['*.log', '*.log.*'])
                ->sortByChangedTime();

            $arr = iterator_to_array(iterator_to_array($f));

            while(count($arr) > $config->logRotate)
            {
                $rm = array_shift($arr);
                unlink($rm->getPathName());
            }
        }
    }
}
