<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper;

class ProgressIndicator extends Helper\ProgressIndicator
{
    public function __construct(OutputInterface $output, private readonly WrkFs $wrkFs)
    {
        parent::__construct($output, 'verbose', 100,
            ['⠏', '⠛', '⠹', '⢸', '⣰', '⣤', '⣆', '⡇'],
            '<info>✔</info>'
        );
    }

    public function advance() : void
    {
        static $i = 0;
        if ($i++ % 10 != 0)
        {
            $this->wrkFs->checkSize();
        }
        parent::advance();
    }
}
