<?php declare(strict_types=1);
namespace TarBSD\Command;

use TarBSD\Builder;
use TarBSD\App;

use Symfony\Component\Console\Command\Command as SfCommand;
use Symfony\Component\Console\Output\OutputInterface;

use DateTimeImmutable;

abstract class AbstractCommand extends SfCommand
{
    const CHECK = ' <info>✔</>';

    const ERR = ' <r>‼️</>';

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
        $output->writeln(self::LOGO);
    }

    protected function showVersion(OutputInterface $output) : void
    {
        $style = '';
        $v = 'dev';

        if (TARBSD_VERSION)
        {
            $v = (TARBSD_PORTS ? 'ports-' : '') . TARBSD_VERSION;

            if (TARBSD_SELF_UPDATE)
            {
                $buidDate = App::getBuildDate();

                if ($buidDate < new DateTimeImmutable('-8 weeks'))
                {
                    $style = '<bg=yellow;options=bold>';
                }
            }
        }

        $output->writeln(sprintf(
            "version: %s%s%s",
            $style,
            $v,
            $style ? '</>' : ''
        ));
    }
}
