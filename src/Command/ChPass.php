<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'chpass',
    description: 'Change tarBSD root password'
)]
class ChPass extends Bootstrap
{
    public function __invoke(InputInterface $input, OutputInterface $output) : int
    {
        $cwd = getcwd();

        if (!file_exists($configFile = $cwd . '/tarbsd.yml'))
        {
            $output->writeln(self::ERR . ' There\'s no tarBSD project here');
            return self::FAILURE;
        }
    
        $this->showLogo($output);

        $config = Yaml::parseFile($configFile);

        $pwSection = $output->section();
        $pwHash = null;
        do
        {
            try
            {
                $pwHash = $this->askPasswd($input, $pwSection);
            }
            catch (\Exception)
            {
                $output->writeln('<error>Password mismatch!</>');
            }
        } while($pwHash === null);

        $config['root_pwhash'] = $pwHash;

        $fs = new Filesystem;
        $fs->dumpFile(
            $configFile,
            $this->genYML($config)
        );

        $pwSection->overwrite(self::CHECK . ' root password changed');

        return self::SUCCESS;
    }
}
