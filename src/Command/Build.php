<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Builder\MfsBuilder;
use TarBSD\Configuration;

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
use Symfony\Component\Process\Process;
use DateTimeImmutable;

#[AsCommand(
    name: 'build',
    description: 'Build tarBSD image'
)]
class Build extends AbstractCommand
{
    const KNOWN_FORMATS = [
        'img', 'qcow2', 'qcow', 'cow', 'vdi', 'vmdk', 'vhdx', 'vpc', 'parallels'
    ];

    public function __invoke(
        OutputInterface $output,
        #[Option('Distribution files (base.txz and kernel.txz) location')] ?string $distfiles = null,
        #[Option('Loosen compression settings')] bool $quick = false,
        #[Argument('Output image formats')] array $formats = [],
        #[Option('Skip cache (for testing)')] bool $doNotCache = false
    ) : int {

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

        if (!$distFiles)
        {
            foreach(['/mnt', '/media', '/cdrom'] as $dir)
            {
                if (null !== $distFiles = $this->findDistributionFiles($dir))
                {
                    break;
                }
            }
            if (!$distFiles)
            {
                throw new \Exception(
                    'Cannot find kernel.txz and base.txz from /cdrom or /mnt'
                    . ', please provide their location with --distfiles=/location'
                    . ' option'
                );
            }
        }

        if (0 < count($formats))
        {
            try
            {
                Process::fromShellCommandline('which qemu-img')->mustRun();
            }
            catch(\Exception $e)
            {
                throw new \Exception(
                    "please install qemu-tools package to use random image formats\n"
                    . "pkg install qemu-tools \n"
                    . "tools flavour of emulators/qemu in ports"
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
        }

        $cache = $doNotCache ? new NullCache : $this->getApplication()->getCache();

        $builder = new MfsBuilder(
            Configuration::get(),
            $cache,
            $distFiles,
            $this->getApplication()->getDispatcher()
        );

        /*
            $logDir = $cwd . '/log';
            $time = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s');
            $logFile = fopen($logDir . '/' . $time . '.log', 'w');
            $verboseOutput = new StreamOutput($logFile);
        */

        if ($output->isVerbose())
        {
            $verboseOutput = $output;
            $output = new NullOutput;
        }
        else
        {
            $this->showLogo($output);
            $this->showBuildTime($output, false);
            $verboseOutput = new NullOutput;
        }

        $img = $builder->build(
            $output,
            $verboseOutput,
            $quick
        );

        foreach($formats as $format)
        {
            Process::fromShellCommandline(sprintf(
                "qemu-img convert -f raw -O %s %s %s",
                $format,
                $img,
                $formattedName = $img->getBasename('.img') . '.' . $format
            ), $img->getPath())->mustRun();

            $output->writeln(MfsBuilder::CHECK . ' wrk/' . $formattedName . ' generated');
        }

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
}
