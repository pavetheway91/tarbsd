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
<c0>               ,       ,       </>
<c0>              /(       )`      </>
<c0>              \ \__   / |      </>
<c0>              /- _ `-/  '      </>
<c0>             (/\/ \ \   /\     </> <c1>  _            </><c2> ____   _____ _____   </>
<c0>             / /   | `    \    </> <c1> | |           </><c2>|  _ \ / ____|  __ \  </>
<c0>             O O   )      |    </> <c1> | |_ __ _ _ __</><c2>| |_) | (___ | |  | | </>
<c0>             `-^--'`<     '    </> <c1> | __/ _` | '__</><c2>|  _ < \___ \| |  | | </>
<c0>            (_.)  _ )    /     </> <c1> | || (_| | |  </><c2>| |_) |____) | |__| | </>
<c0>             `.___/`    /      </> <c1>  \__\__,_|_|  </><c2>|____/|_____/|_____/  </>
<c0>               `-----' /       </> <c3>   _           _ _     _             </>
<c0>  <----.     __ / __   \       </> <c3>  | |         (_) |   | |            </>
<c0>  <----|====O)))==) \) /====   </> <c3>  | |__  _   _ _| | __| | ___ _ __   </>
<c0>  <----'    `--' `.__,' \      </> <c3>  | '_ \| | | | | |/ _` |/ _ \ '__|  </> 
<c0>               |         |     </> <c3>  | |_) | |_| | | | (_| |  __/ |     </>
<c0>               \       /       </> <c3>  |_.__/ \__,_|_|_|\__,_|\___|_|     </>
<c0>            ____( (_   / \______</>   
<c0>          ,'  ,----'   |        \ </>
<c0>          `--{__________)       \/ </>
LOGO;

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
                    $style = '<c1g=yellow;options=bold>';
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
