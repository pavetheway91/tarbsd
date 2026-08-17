<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;

use TarBSD\Util\WrkFs;
use TarBSD\App;

use DateTimeImmutable;

#[AsCommand(
    name: 'wrkfssize',
    hidden: true
)]
class WrkFsSize extends AbstractCommand
{
    public function __invoke(
        OutputInterface $output,
        #[Argument()] string $runId,
    ) : int {

        if (App::amIRoot() && preg_match('/^([0-9a-f]{16})$/', $runId))
        {
            $wrkFs = WrkFs::get(getcwd());
            while(true)
            {
                try
                {
                    $wrkFs->checkSize();
                    usleep(250000);
                }
                catch(\Exception $e)
                {
                    if (extension_loaded('posix'))
                    {
                        $cache = $this->getApplication()->getCache();
                        $item = $cache->getItem($runId);
                        $item->set($e->getMessage())->expiresAt(new DateTimeImmutable('+5 seconds'));
                        $cache->save($item);
                        posix_kill(posix_getppid(), \SIGTERM);
                    }
                    return self::FAILURE;
                }
            }
        }
    }
}
