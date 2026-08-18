<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;

use TarBSD\Util\WrkFs;
use TarBSD\Util\Misc;
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
        #[Argument()] int $ftok,
        #[Argument()] string $key
    ) : int {

        if (App::amIRoot() && msg_queue_exists($ftok))
        {
            $q = msg_get_queue($ftok);

            msg_receive($q, 0, $type, 1024, $msg, false, MSG_IPC_NOWAIT);
            if ($msg == $key)
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
                        msg_send($q, 1, $e->getMessage(), false);
                        posix_kill(posix_getppid(), \SIGTERM);
                        return self::FAILURE;
                    }
                }
            }
            else
            {
                msg_send($q, 1, 'failed to start wrkfs worker', false);
                posix_kill(posix_getppid(), \SIGTERM);
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
