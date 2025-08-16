<?php declare(strict_types=1);
namespace TarBSD\Util;

use RuntimeException;
use Stringable;

class Fstab implements Stringable
{
    const LINE_REGEX = '/^'
        . '([a-zA-Z0-9\/-_.,=]+)\s+'
        . '([a-zA-Z0-9\/-_.,=]+)\s+'
        . '([a-zA-Z0-9\/-_.=]+)\s+'
        . '([a-zA-Z0-9\/-_.,=]+)\s+'
        . '(1|0)\s+(1|0)(?:\s+\#(.*))?'
    . '/';

    private array $lengths = [0, 0, 0, 0, 0, 0];

    private array $lines = [];

    public function __toString() : string
    {
        return $this->format();
    }

    public static function fromString(string $str, bool $preserveComments = true) : static
    {
        $lines = array_map('trim', explode("\n", $str));
        $out = new static;

        foreach($lines as $index => $line)
        {
            if (preg_match(self::LINE_REGEX, $line, $m))
            {
                $m[7] = (isset($m[7]) && $preserveComments) ? $m[7] : null;
                $out->addLine(
                    $m[1], $m[2], $m[3], $m[4],
                    intval($m[5]), intval($m[6]), $m[7]
                );
            }
            elseif ($preserveComments && $index > 0)
            {
                $out->lines[] = $line;
            }
        }
        return $out;
    }

    public static function fromFile(string $file, bool $preserveComments = true) : static
    {
        if (!is_readable($file))
        {
            if (!file_exists($file))
            {
                throw new RuntimeException(sprintf(
                    "File %s does not exist",
                    $file
                ));
            }
            throw new RuntimeException(sprintf(
                "File %s is not readable",
                $file
            ));
        }
        if (is_dir($file))
        {
            throw new RuntimeException(sprintf(
                "%s is a directory",
                $file
            ));
        }

        return static::fromString(
            file_get_contents($file),
            $preserveComments
        );
    }

    public function addLine(
        string $device,
        string $mnt,
        string $type,
        string|array $options,
        int $dump = 0,
        int $pass = 0,
        ?string $comment = null
    ) : void {
        if (is_array($options))
        {
            $options = $this->optionsToStr($options);
        }

        $lengths = array_map('strlen', [$device, $mnt, $type, $options]);

        foreach($lengths as $index => $value)
        {
            if ($this->lengths[$index] < $value)
            {
                $this->lengths[$index] = $value;
            }
        }

        $this->lines[] = [$device, $mnt, $type, $options, $dump, $pass, $comment];
    }

    public function addEmptyLine() : void
    {
        $this->lines[] = '';
    }

    public function addComment(string $comment) : void
    {
        $this->lines[] = '# ' . preg_replace('/\n/', "\n# ", $comment);
    }

    public function append(Fstab $another) : void
    {
        foreach($another->lines as $line)
        {
            if (is_array($line))
            {
                $this->addLine(
                    $line[0], $line[1], $line[2], $line[3],
                    $line[4], $line[5], $line[6]
                );
            }
            else
            {
                $this->lines[] = $line;
            }
        }
    }

    public function format(int $space = 4, bool $header = true) : string
    {
        if ($space < 1)
        {
            throw new \Exception;
        }

        $lengths = $this->lengths;

        $lines = [];

        if ($header)
        {
            $lines[-1] = ['# Device', 'Mountpoint', 'FStype', 'Options', 'Dump', 'Pass #'];

            $headerLengths = array_map('strlen', $lines[-1]);

            foreach($headerLengths as $index => $value)
            {
                if ($lengths[$index] < $value)
                {
                    $lengths[$index] = $value;
                }
            }
        }

        $lines = $lines + $this->lines;

        return implode("\n", array_map(function($line) use ($space, $lengths) {

            if (is_string($line))
            {
                return $line;
            }

            return implode(
                str_repeat(" ", $space),
                [
                    str_pad($line[0], $lengths[0], ' ', STR_PAD_RIGHT),
                    str_pad($line[1], $lengths[1], ' ', STR_PAD_RIGHT),
                    str_pad($line[2], $lengths[2], ' ', STR_PAD_RIGHT),
                    str_pad($line[3], $lengths[3], ' ', STR_PAD_RIGHT),
                    str_pad(strval($line[4]), $lengths[4], ' ', STR_PAD_RIGHT),
                    $line[5],
                    isset($line[6]) && is_string($line[6]) ? '#' . $line[6] : ''
                ]
            );
        }, $lines)) . "\n";
    }

    public function __debugInfo() : array
    {
        return ["data" => "\n" . $this];
    }

    protected function optionsToStr(array $options) : string
    {
        if (array_is_list($options))
        {
            return implode(',', $options);
        }
        else
        {
            $newOptions = [];

            foreach($options as $key => $value)
            {
                if (is_string($key) && is_string($value))
                {
                    $newOptions[] = $key . '=' . $value;
                }
                elseif (is_string($value))
                {
                    $newOptions[] = $value;
                }
            }

            return implode(',', $newOptions);
        }
    }
}
