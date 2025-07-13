<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Builder;
use TarBSD\App;

use Symfony\Component\Console\Command\Command as SfCommand;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;

use DateTimeImmutable;

abstract class AbstractCommand extends SfCommand
{
    const CHECK = ' <info>✔</>';

    const ERR = ' <fg=red>‼️</>';

    const LOGO = <<<LOGO
<r>               ,       ,       </>
<r>              /(       )`      </>
<r>              \ \__   / |      </>
<r>              /- _ `-/  '      </>
<r>             (/\/ \ \   /\     </> <b> _            </><r> ____   _____ _____  </>
<r>             / /   | `    \    </> <b>| |           </><r>|  _ \ / ____|  __ \ </>
<r>             O O   )      |    </> <b>| |_ __ _ _ __</><r>| |_) | (___ | |  | |</>
<r>             `-^--'`<     '    </> <b>| __/ _` | '__</><r>|  _ < \___ \| |  | |</>
<r>            (_.)  _ )    /     </> <b>| || (_| | |  </><r>| |_) |____) | |__| |</>
<r>             `.___/`    /      </>  <b>\__\__,_|_|  </><r>|____/|_____/|_____/ </>
<r>               `-----' /       </>    <g>_           _ _     _ </>
<r>  <----.     __ / __   \       </>   <g>| |         (_) |   | | </>
<r>  <----|====O)))==) \) /====   </>   <g>| |__  _   _ _| | __| | ___ _ __</>
<r>  <----'    `--' `.__,' \      </>   <g>| '_ \| | | | | |/ _` |/ _ \ '__|</> 
<r>               |         |     </>   <g>| |_) | |_| | | | (_| |  __/ |</>
<r>               \       /       </>   <g>|_.__/ \__,_|_|_|\__,_|\___|_|</>
<r>            ____( (_   / \______</>   
<r>          ,'  ,----'   |        \ </>
<r>          `--{__________)       \/ </>
LOGO;

    protected function showLogo(OutputInterface $output) : void
    {
        $output->getFormatter()->setStyle('r', new OutputFormatterStyle('red'));
        $output->getFormatter()->setStyle('b', new OutputFormatterStyle('blue'));
        $output->getFormatter()->setStyle('g', new OutputFormatterStyle('green'));
        $output->writeln(self::LOGO);
    }

    protected function showBuildTime(OutputInterface $output, bool $always) : void
    {
        $show = $always;

        if ($buidDate = App::getBuildDate())
        {
            $style = '';

            if ($buidDate < new DateTimeImmutable('-2 weeks'))
            {
                /**
                 * Friendly reminder to check if there's
                 * an update available.
                 */
                $style = '<bg=yellow;options=bold>';
                $show = true;
            }

            if ($show)
            {
                $output->writeln(sprintf(
                    'This version of tarBSD builder was built at %s%s%s',
                    $style,
                    $buidDate->format('Y-m-d H:i:s \\U\\T\\C'),
                    $style ? '</>' : ''
                ));
            }
        }
    }
}
