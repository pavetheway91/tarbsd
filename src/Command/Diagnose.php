<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Process\Process;
use Symfony\Component\Finder\Finder;

use DateTimeImmutable;
use TarBSD\Util\Misc;
use TarBSD\Builder;
use TarBSD\App;
use Phar;

#[AsCommand(
    name: 'diagnose',
    description: 'Paste output of this to bug reports'
)]
class Diagnose extends AbstractCommand
{
    const SKIP_EXTS = [
        'core', 'date', 'hash', 'json', 'lexbor', 'libxml', 'mysqlnd', 'pcre',
        'random', 'reflection', 'spl', 'standard', 'uri', 'zend opcache',
        'phar', 'ctype', 'mbstring', 'iconv', 'intl', 'libdeflate'
    ];

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
    ) {
        $output->writeln(static::pad('uname:') . php_uname());
        $output->writeln(static::pad('version:') . (TARBSD_VERSION ?: 'dev'));
        if (str_starts_with(__FILE__, 'phar://'))
        {
            $this->getPharInfo($output);
        }
        if (TARBSD_PORTS && Phar::running(false) == TARBSD_PREFIX . '/bin/tarbsd')
        {
            $this->portInfo($output);
        }
        $output->writeln(static::pad('prefix:') . TARBSD_PREFIX);
        $output->writeln(static::pad('php version:') . PHP_VERSION);
        $output->writeln(static::pad('php path:') . PHP_BINARY);
        $output->writeln(static::pad('php zts:') . (PHP_ZTS ? '<comment>yep</>' : 'nope'));
        $output->writeln(static::pad('openssl:') . OPENSSL_VERSION_TEXT);
        $output->writeln(static::pad('pigz:') . (Misc::hasPigz() ? '<info>installed</>' : '<comment>not installed</>'));
        $output->writeln(static::pad('libdeflate:') . $this->getExtensionStatus('libdeflate'));
        $output->writeln(static::pad('ctype:') . $this->getExtensionStatus('ctype'));
        $output->writeln(static::pad('iconv:') . $this->getExtensionStatus('iconv'));
        $output->writeln(static::pad('mbstring:') . $this->getExtensionStatus('mbstring'));
        $output->writeln(static::pad('intl:') . $this->getExtensionStatus('intl'));
        $output->writeln(static::pad('additional extensions:') . $this->additionalExts());

        return self::SUCCESS;
    }

    protected static function pad(string $str) : string
    {
        return str_pad($str, 23);
    }

    protected function getPharInfo(OutputInterface $output) : void
    {
        $n = 0;

        foreach((new Finder)->files()->in(Phar::running(true)) as $file)
        {
            $n++;
        }

        $output->writeln(static::pad('phar:') . Phar::running(false));

        $output->writeln(static::pad('phar files:') . $n);

        $output->writeln(static::pad('phar alias:') . (new Phar(Phar::running(false)))->getAlias());
    }

    protected function portInfo(OutputInterface $output) : void
    {
        try
        {
            $p = Process::fromShellCommandline('pkg info | grep tarbsd-builder')->mustRun()->getOutput();
            if (preg_match('/(tarbsd-builder-php([0-9]{1,3}))/', $p, $m))
            {
                $p = Process::fromShellCommandline('pkg info ' . $m[1])->mustRun()->getOutput();
                if (preg_match('/build_timestamp:(.*?)\n/', $p, $m))
                {
                    $date = (new DateTimeImmutable(trim($m[1], " ")))->format('Y-m-d H:i:s');
                    $output->writeln(static::pad('port built:') . $date);
                }
                if (preg_match('/ports_top_git_hash:(.*?)\n/', $p, $m))
                {
                    $output->writeln(static::pad('ports tree hash:') . trim($m[1], " "));
                }
            }
            else
            {
                throw new \Exception;
            }
        }
        catch(\Exception $e)
        {
            $output->writeln(static::pad('port info:') . '<r>error</>');
        }
    }

    protected function getExtensionStatus(string $ext) : string
    {
        if (extension_loaded($ext))
        {
            return '<info>loaded</>';
        }

        if (file_exists(ini_get('extension_dir') . '/'. $ext . '.so'))
        {
            return '<comment>installed, not loaded</>';
        }

        return '<comment>not installed</>';
    }

    protected function additionalExts() : string
    {
        $reqs = Misc::phpRequirements();
        $notInteresting = array_merge(self::SKIP_EXTS, $reqs['extensions']);
        $exts = array_diff(array_map('strtolower', get_loaded_extensions()), $notInteresting);
        return implode(" ", $exts);
    }
}
