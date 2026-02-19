<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Symfony\Polyfill\Uuid\Uuid;

use TarBSD\Configuration;
use TarBSD\Util\Misc;
use TarBSD\Builder;

#[AsCommand(
    name: 'bootstrap',
    description: 'Bootstrap a new tarBSD project'
)]
class Bootstrap extends AbstractCommand
{
    const ZONE_INFO = '/usr/share/zoneinfo/';

    public function __invoke(InputInterface $input, OutputInterface $output) : int
    {
        $cwd = getcwd();

        if (file_exists($configFile = $cwd . '/tarbsd.yml'))
        {
            $output->writeln(self::ERR . ' There\'s already a tarBSD project here');
            return self::SUCCESS;
        }

        $config = Yaml::parseFile(TARBSD_STUBS . '/tarbsd.yml');

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
        $pwSection->overwrite(self::CHECK . ' root password set');

        $sshSection = $output->section();
        $sshKey = $this->askSshKey($input, $sshSection);
        if ($sshKey)
        {
            $type = '';
            if (preg_match('/^(?:ssh-)?(rsa|ed25519|ecdsa|dss)/', $sshKey, $m))
            {
                $type = $m[1];
            }
            $sshSection->overwrite(self::CHECK . ' SSH key ' . $type);
            $sshKey = str_replace("\n", '', $sshKey);
        }
        else
        {
            $sshSection->overwrite(self::CHECK . ' no root SSH key');
        }

        $tzSection = $output->section();
        $tz = $this->askTz($input, $tzSection);
        $tzSection->overwrite(self::CHECK . ' selected timezone ' . $tz);

        $config['root_pwhash'] = $pwHash;
        $config['root_sshkey'] = $sshKey;

        $fs = new Filesystem;
        $fs->dumpFile(
            $configFile,
            $this->genYML($config)
        );

        $fs->mirror(
            TARBSD_STUBS . '/overlay',
            $overlay = $cwd . '/tarbsd'
        );
        $fs->symlink(
            '../usr/share/zoneinfo/' . $tz,
            $overlay . '/etc/localtime'
        );

        $fs->dumpFile(
            $overlay . '/etc/hostid',
            $hostid = Misc::genUuid() . "\n"
        );
        $fs->dumpFile(
            $overlay . '/etc/machine-id',
            str_replace('-', '', $hostid)
        );

        $this->showLogo($output->section());

        $output->writeln(
            "\n" . self::CHECK . ' Everything\'s set up. Take a look at tarbsd.yml'
            . " as well contents\n   of the overlay directory called tarbsd.\n"
        );

        return self::SUCCESS;
    }

    protected function genYML(array $config) : string
    {
        $yml = Yaml::dump($config, 4, 4);
        $yml = preg_replace([
            "/\n    early:/",
            "/\n    late:/",
            "/ly\: null/",
            "/\n    wifi:/",
            "/\n    ntpd:/"
        ], [
            "\n    # kernel modules to be loaded right at boot\n    early:",
            "\n    # kernel modules to be available later\n    late:",
            "ly:",
            "\n    # wifi kernel modules are not covered by the feature\n    wifi:",
            "\n    # busybox has ntpd too\n    ntpd:"
        ], $yml);
        return $yml;
    }

    protected function askPasswd(InputInterface $input, OutputInterface $output) : string
    {
        $helper = $this->getHelper('question');

        $question = new Question(" Password for the root user");
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function ($value) : string
        {
            if (!is_string($value) || strlen($value) < 9)
            {
                throw new \Exception(" At least 9 characters required");
            }
            return $value;
        });
        $passwd = $helper->ask($input, $output, $question);

        $question = new Question(" Write the password again");
        $question->setValidator(function ($value) use ($passwd) : string
        {
            if ($passwd !== $value) {
                throw new \Exception;
            }
            return $value;
        });
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setMaxAttempts(1);
        $password = $helper->ask($input, $output, $question);

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    protected function askSshKey(InputInterface $input, OutputInterface $output) : string|null
    {
        $helper = $this->getHelper('question');

        $question = new Question(
            " SSH key for the root user\n"
        );
        $question->setValidator(function ($value) : string|null
        {
            if (
                is_string($value)
                &&
                (!preg_match('/^(?:ssh-)?(rsa|ed25519|ecdsa|dss)/', $value) || strlen($value) < 80)
            ) {
                throw new \Exception('This doesn\'t seem like a SSH key');
            }
            return $value;
        });

        return $helper->ask($input, $output, $question);
    }

    protected function askBool(string $question, InputInterface $input, OutputInterface $output) : bool
    {
        $helper = $this->getHelper('question');

        $question = new Question($question . " ");

        $question->setValidator(function ($value) : bool
        {
            if (
                is_string($value)
                &&
                in_array($value, ['y', 'n', 'yes', 'no', 'yep', 'nope'])
            ) {
                return $value[0] == 'y';
            }
            throw new \Exception('(y)yes or (n)no');
        });
        return $helper->ask($input, $output, $question);
    }

    protected function askTz(InputInterface $input, OutputInterface $output) : string
    {
        $zoneInfoLen = strlen(self::ZONE_INFO) + 1;

        $options = (new Finder)->files()->in(self::ZONE_INFO)->notname('.tab')->notname('.zi');
        $zones = array_map(function($tz) use ($zoneInfoLen)
        {
            return $tz->getRelativePathName();
        }, iterator_to_array($options));

        $helper = $this->getHelper('question');

        $question = new Question(
            "Write your timezone (eg. Europe/Helsinki, US/Eastern, Africa/Johannesburg)\nleave blank for UTC\n",
            'UTC'
        );
        $question->setAutocompleterValues($zones);

        $question->setValidator(function ($value) use ($zones) : string
        {
            if (array_search($value, $zones))
            {
                return $value;
            }
            throw new \Exception($value . ' was not in the time zone database');
        });

        return $helper->ask($input, $output, $question);
    }
}
