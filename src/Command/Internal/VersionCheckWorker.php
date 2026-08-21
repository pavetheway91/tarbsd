<?php declare(strict_types=1);
namespace TarBSD\Command\Internal;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use TarBSD\Util\UpdateUtil;
use DateTimeImmutable;

#[AsCommand(
    name: 'version-check',
    hidden: true
)]
class VersionCheckWorker extends InternalCommand
{
    public function __invoke(OutputInterface $output) : int
    {
        if (TARBSD_SELF_UPDATE)
        {
            $item = $this->getVersionCheckItem();
            if (!$item->isHit() || $item->get() !== true)
            {
                if (is_array(UpdateUtil::getLatest($this->getApplication()->getHttpClient(), false)))
                {
                    $item->set(true)->expiresAt(new DateTimeImmutable('+1 year'));
                    $this->getApplication()->getCache()->save($item);
                    $output->write('update available');
                }
            }
        }
        return self::SUCCESS;
    }
}
