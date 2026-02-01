<?php declare(strict_types=1);
namespace TarBSD\Builder\Traits;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

use TarBSD\Util\ProgressIndicator;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait Utils
{
    protected function getKernelModuleDirs() : array
    {
        return [
            $this->root . '/boot/kernel',
            $this->root . '/boot/modules'
        ];
    }

    protected function progressIndicator(OutputInterface $output) : ProgressIndicator
    {
        return new ProgressIndicator($output, $this->wrkFs);
    }

    final protected function hasKernelModule(string $name) : bool
    {
        if (true !== $this->bootPruned)
        {
            throw new \Exceptio('this should not be called yet');
        }
        if (null === $this->modules)
        {
            $f = (new Finder)->files()
                ->in($this->getKernelModuleDirs())
                ->name(['*.ko', '*.ko.gz']);

            $f = array_map(function($info)
            {
                $parts = explode('.', $info->getFilename());
                return $parts[0];
            }, iterator_to_array($f));
            $this->modules = array_flip($f);
        }
        return isset($this->modules[$name]);
    }

    final protected function ensureSSHkeysExist(OutputInterface $output, OutputInterface $verboseOutput) : void
    {
        $this->fs->mkdir($keys = $this->filesDir . '/etc/ssh');
        $new = false;
        foreach(['rsa', 'ecdsa', 'ed25519'] as $alg)
        {
            $keyFile = $keys . '/ssh_host_' . $alg . '_key';
            $keyFilePub = $keyFile . '.pub';

            if (!$this->fs->exists($keyFile))
            {
                $cmd = <<<CMD
/usr/bin/ssh-keygen -q -t $alg -f $keyFile -N ""
/usr/bin/ssh-keygen -l -f $keyFilePub
CMD;
                $new = true;
                Process::fromShellCommandline($cmd)->mustRun();
            }
        }
        if ($new)
        {
            $output->writeln(self::CHECK . ' generated SSH host keys to the overlay directory');
        }
    }

    final protected function getRequiredPackages() : array
    {
        $packages = $this->config->getPackages();

        if ($this->config->isBusyBox())
        {
            $packages[] = 'busybox';
        }

        if ($this->config->getSSH() == 'dropbear')
        {
            $packages[] = 'dropbear';
        }

        foreach($this->config->features() as $f)
        {
            if ($f->isEnabled())
            {
                foreach($f->getPackages() as $pkg)
                {
                    $packages[] = $pkg;
                }
            }
        }

        return array_unique($packages);
    }

    final protected function getEarlyModules() : array
    {
        $modules = $this->config->getEarlyModules();

        foreach($this->config->features() as $f)
        {
            if ($f->isEnabled())
            {
                foreach($f->getKmods() as $module => $early)
                {
                    if ($early)
                    {
                        $modules[] = $module;
                    }
                }
            }
        }

        return array_unique($modules);
    }

    final protected function getModules() : array
    {
        $modules = $this->config->getModules();

        if ($this->config->isBusyBox())
        {
            $modules[] = 'linprocfs.ko';
            //$modules[] = 'linsysfs.ko';
            $modules[] = 'linux_common.ko';
        }
        foreach($this->config->features() as $f)
        {
            if ($f->isEnabled())
            {
                foreach($f->getKmods() as $moddule => $early)
                {
                    if (!$early)
                    {
                        $modules[] = $moddule;
                    }
                }
            }
        }

        return $modules;
    }
}
