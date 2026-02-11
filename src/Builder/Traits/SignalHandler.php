<?php declare(strict_types=1);
namespace TarBSD\Builder\Traits;

use Symfony\Component\Console\SignalRegistry\SignalMap;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;
use TarBSD\Util\Misc;

trait SignalHandler
{
    final public function handleSignal(ConsoleSignalEvent $event) : void
    {
        switch($event->getHandlingSignal())
        {
            case \SIGINT:
            case \SIGTERM:
                $output = $event->getOutput();

                $output->writeln(sprintf(
                    "\n%s received %s signal, cleaning things up...",
                    self::ERR,
                    SignalMap::getSignalName($event->getHandlingSignal())
                ));

                $df = Process::fromShellCommandline(
                    'df -t tmpfs,nullfs --libxo=json'
                );
                $df = json_decode($df->mustRun()->getOutput(), true);

                $mounts = false;

                foreach($df['storage-system-information']['filesystem'] as $fs)
                {
                    if (str_starts_with($fs['mounted-on'], $this->wrk))
                    {
                        $mounts = true;
                        try
                        {
                            Process::fromShellCommandline(sprintf(
                                'umount -f %s',
                                $fs['mounted-on']
                            ))->mustRun();
                            $output->writeln(sprintf(
                                '%s umounted %s',
                                self::CHECK,
                                $fs['mounted-on']
                            ));
                        }
                        catch (\Exception $e)
                        {
                            $output->writeln(sprintf(
                                '%s failed to umount %s',
                                self::ERR,
                                $fs['mounted-on']
                            ));
                        }
                    }
                }

                if (!$mounts)
                {
                    $output->writeln(self::CHECK . ' no temporary mounts');
                }

                $f = (new Finder)
                    ->in($this->wrk)
                    ->name(['*.img', 'boot', 'efi'])
                    ->depth(0);

                $this->fs->remove($f);

                if ($this->md)
                {
                    Misc::mdDestroy($this->md);
                }

                $output->writeln(self::CHECK . ' rm\'d temporary files');
                break;
        }
    }
}