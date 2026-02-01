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

    /**
     * Unlike /usr/bin/gzip, this gives real-time
     * progress updates allowing progress indicator
     * to spin.
     */
    final protected function zlibCompress(string $file, int $level, ProgressIndicator $progressIndicator) : void
    {
        if (!$this->fs->exists($file))
        {
            throw new \RuntimeException(sprintf(
                "%s does not exist",
                $file
            ));
        }

        $in = fopen($file, 'r');
        $out = gzopen($file . '.gz', 'wb' . $level);

        while (!feof($in))
        {
            gzwrite($out, fread($in, 1048576));
            $progressIndicator->advance();
        }

        fclose($in);
        gzclose($out);
        $this->fs->remove($file);
    }

    final protected function pigzCompress(string $file, int $level, ProgressIndicator $progressIndicator) : void
    {
        if (!$this->fs->exists($file))
        {
            throw new \RuntimeException(sprintf(
                "%s does not exist",
                $file
            ));
        }

        $out = fopen($file . '.gz', 'wb');

        $p = Process::fromShellCommandline(
            sprintf("pigz -%d -c %s", $level, $file),
            null, null, null, 1800
        )->mustRun(function ($type, $buffer) use ($out, $progressIndicator)
        {
            $progressIndicator->advance();
            fwrite($out, $buffer);
        });
        fclose($out);
        $this->fs->remove($file);
    }

    /**
     * Copies contents of one directory to another using tar.
     */
    final protected function tarStream(string $from, string $to, OutputInterface $verboseOutput) : void
    {
        Process::fromShellCommandline(
            'tar cf - . | (cd ' . $to . ' && tar xvf -)',
            $from,
            null,
            null, 
            600
        )->mustRun(function ($type, $buffer) use ($verboseOutput)
        {
            $verboseOutput->write($buffer);
        });
    }

    final protected function getFileSizeM(string $file) : int
    {
        if (!file_exists($file))
        {
            throw new \RuntimeException(sprintf(
                "%s does not exist",
                $file
            ));
        }
        if (is_dir($file))
        {
            $size = 0;
            $i = new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS);
            foreach(new RecursiveIteratorIterator($i) as $object){
                $size += $object->getSize();
            }
        }
        else
        {
            $size = filesize($file);
        }
        $mbSize = $size / 1048576;
        return (int) number_format($mbSize, 0, '', '');
    }

    final protected function encodeTarOptions(array $arr) : string
    {
        /**
         * http_build_query might work too, but I'm not sure
         * if tar likes it's booleans. 
         */
        $out = [];
        foreach($arr as $key => $value)
        {
            switch(gettype($value))
            {
                case 'integer':
                case 'string':
                    $out[] = $key . '=' . $value;
                    break;
                case 'boolean':
                    $out[] = ($value ? '' : '!') . $key;
                    break;
            }
        }
        return implode(',', $out);
    }

    final protected function hasPigz() : bool
    {
        static $hasPigz;

        if (null === $hasPigz)
        {
            try
            {
                Process::fromShellCommandline('pigz -h')->mustRun();
                $hasPigz = true;
            }
            catch (\Exception $e)
            {
                $hasPigz = false;
            }
        }

        return $hasPigz;
    }
}
