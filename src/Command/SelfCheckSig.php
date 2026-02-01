<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;

use TarBSD\Util\UpdateUtil;

/**
 * Verifies that releases are properly
 * signed before they go to GitHub.
 */
#[AsCommand(
    name: 'checkSig',
    hidden: true
)]
class SelfCheckSig extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Option('phar')] ?string $phar = null,
        #[Option('type')] string $type = 'secp384r1'
    ) : int
    {
        $phar = $phar ?: $_SERVER['SCRIPT_FILENAME'];
        $phar = realpath($phar);

        if (!file_exists($sigFile = $phar . '.sig.' . $type))
        {
            throw new \Exception('cannot find ' . $sigFile);
        }

        if ('sha256' === $type)
        {
            $check = function($phar, $sigFile)
            {
                return hash_file('sha256', $phar) === file_get_contents($sigFile);
            };
        }
        else
        {
            $check = function($phar, $sigFile)
            {
                return UpdateUtil::validateEC(
                    $phar, file_get_contents($sigFile)
                );
            };
        }

        if ($check($phar, $sigFile))
        {
            $output->writeln(sprintf(
                "signature check of %s\n<info>passed using</>: %s",
                $phar,
                $sigFile
            ));
        }
        else
        {
            $output->writeln(sprintf(
                "signature check of %s\n<error>failed using</>: %s",
                $phar,
                $sigFile
            ));
        }

        return self::SUCCESS;
    }
}
