<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Command\Command as SfCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Cache\CacheItem;

use TarBSD\Util\Icons;
use TarBSD\Builder;
use TarBSD\App;

use DateTimeImmutable;
use Phar;

abstract class AbstractCommand extends SfCommand implements Icons
{
    const LOGO = <<<LOGO
<c0>              ,       ,     </>
<c0>             /(       )`    </>
<c0>             \ \__   / |    </>
<c0>             /- _ `-/  '    </>
<c0>            (/\/ \ \   /\   </><c1> _            </><c2> ____   _____ _____ </>
<c0>            / /   | `    \  </><c1>| |           </><c2>|  _ \ / ____|  __ \ </>
<c0>            O O   )      |  </><c1>| |_ __ _ _ __</><c2>| |_) | (___ | |  | |</>
<c0>            `-^--'`<     '  </><c1>| __/ _` | '__</><c2>|  _ < \___ \| |  | |</>
<c0>           (_.)  _ )    /   </><c1>| || (_| | |  </><c2>| |_) |____) | |__| |</>
<c0>            `.___/`    /    </><c1> \__\__,_|_|  </><c2>|____/|_____/|_____/</>
<c0>              `-----' /     </><c3>  _           _ _     _           </>
<c0> <----.     __ / __   \     </><c3> | |         (_) |   | |          </>
<c0> <----|====O)))==) \) /==== </><c3> | |__  _   _ _| | __| | ___ _ __ </>
<c0> <----'    `--' `.__,' \    </><c3> | '_ \| | | | | |/ _` |/ _ \ '__|</> 
<c0>              |         |   </><c3> | |_) | |_| | | | (_| |  __/ |   </>
<c0>              \       /     </><c3> |_.__/ \__,_|_|_|\__,_|\___|_|   </>
<c0>           ____( (_   / \______</>   
<c0>         ,'  ,----'   |        \ </>
<c0>         `--{__________)       \/ </>
LOGO;

    const PHP_VERSIONS = [
        '8.2' => [
            "activeSupportEndDate" => "2024-12-31",
            "eolDate"              => "2026-12-31"
        ],
        '8.3' => [
            "activeSupportEndDate" => "2025-12-31",
            "eolDate"              => "2027-12-31"
        ],
        '8.4' => [
            "activeSupportEndDate" => "2026-12-31",
            "eolDate"              => "2028-12-31"
        ],
        '8.5' => [
            "activeSupportEndDate" => "2027-12-31",
            "eolDate"              => "2029-12-31"
        ]
    ];

    protected function showLogo(OutputInterface $output) : void
    {
        $logo = preg_replace(
            ['/<c0>/', '/<c1>/', '/<c2>/', '/<c3>/'],
            [
                '<fg=red;options=bold>',
                '<fg=blue;options=bold>',
                '<fg=red;options=bold>',
                '<fg=green;options=bold>'
            ],
            self::LOGO
        );

        $output->writeln($logo);
    }

    protected function showVersion(OutputInterface $output) : void
    {
        $v = 'dev';

        if (TARBSD_VERSION)
        {
            $v = (TARBSD_PORTS ? 'ports-' : '') . TARBSD_VERSION;
        }

        $output->writeln(sprintf(
            " version: %s%s",
            $v,
            (defined('TARBSD_DEBUG') && TARBSD_DEBUG) ? '  <comment>debug mode</>' : ''
        ));

        $this->showUpdateMessage($output);

        $phpVer = PHP_MAJOR_VERSION . '.' .  PHP_MINOR_VERSION;

        if (isset(self::PHP_VERSIONS[$phpVer]))
        {
            $activeSupportEndDate = new DateTimeImmutable(
                self::PHP_VERSIONS[$phpVer]['activeSupportEndDate']
            );
            $eolDate = new DateTimeImmutable(
                self::PHP_VERSIONS[$phpVer]['eolDate']
            );
            $now = new DateTimeImmutable;
            if ($eolDate < $now)
            {
                $output->writeln(sprintf(
                    "%s PHP %s reached its EOL in %s, please consider updating.",
                    self::ERR,
                    $phpVer,
                    self::PHP_VERSIONS[$phpVer]['eolDate'],
                ));
            }
            elseif ($activeSupportEndDate->modify('+6 months') < $now)
            {
                $output->writeln(sprintf(
                    "%s PHP %s reached end of its active support on %s"
                    . "\n   and will be EOL in %s. Please consider updating.",
                    self::ERR,
                    $phpVer,
                    self::PHP_VERSIONS[$phpVer]['activeSupportEndDate'],
                    self::PHP_VERSIONS[$phpVer]['eolDate'],
                ));
            }
        }
    }

    protected function getVersionCheckItem() : CacheItem
    {
        return $this->getApplication()->getCache()->getItem(
            hash_hmac('sha256', 'update_available', App::hashPhar())
        );
    }

    protected function showUpdateMessage(OutputInterface $output)
    {
        if (TARBSD_SELF_UPDATE)
        {
            $item = $this->getVersionCheckItem();
            if ($item->isHit() && $item->get() === true)
            {
                $output->writeln(sprintf(
                    "%s A newer version tarBSD builder is available, update with"
                    . "\n   self-update command.",
                    self::ERR
                ));
            }
        }
    }
}
