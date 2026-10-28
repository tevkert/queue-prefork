<?php

namespace Tevkert\QueuePrefork\Support;

use InvalidArgumentException;

/**
 * Parses the command lines Horizon builds for supervisors and workers, e.g.
 *
 *   exec '/usr/bin/php8.4' artisan horizon:work redis --name=default --supervisor=h-AbCd:x --queue="a,b" …
 *
 * into the entry script and the argv a stock exec would see. Dependency-free. Anything beyond plain
 * words and quotes (pipes, `;`, substitutions, env assignments, wrappers) is rejected, and the caller
 * falls back to stock.
 */
final class CommandLine
{
    /**
     * @param  string  $script  the entry script as written (relative to the working directory, or absolute)
     * @param  list<string>  $argv  argv as PHP would see it: [script, command, args…]
     */
    private function __construct(
        public readonly string $php,
        public readonly string $script,
        public readonly array $argv,
        public readonly string $command,
    ) {}

    public static function parse(string $line, array $allowedCommands = ['horizon:work', 'horizon:supervisor']): self
    {
        $words = self::split($line);

        if (($words[0] ?? null) === 'exec') {
            array_shift($words);
        }

        if (count($words) < 3) {
            throw new InvalidArgumentException('Command line too short: '.$line);
        }

        [$php, $script, $command] = [$words[0], $words[1], $words[2]];

        if (! in_array($command, $allowedCommands, true)) {
            throw new InvalidArgumentException("Unexpected command [{$command}] in: {$line}");
        }

        if (str_contains($php, '=') || str_starts_with($script, '-')) {
            throw new InvalidArgumentException('Unsupported command line: '.$line);
        }

        return new self($php, $script, array_slice($words, 1), $command);
    }

    /** PHP CLI options whose value is the next argument (`-f` and `--` end the options instead). */
    public const PHP_VALUE_OPTIONS = ['-d', '-c', '-z', '--define', '--php-ini', '--zend-extension'];

    /**
     * A whole process command line ([php, its options…, script, command, args…]) without PHP's own
     * options: what fromArgv() takes.
     *
     * @param  list<string>  $cmdline
     */
    public static function fromProcess(array $cmdline, array $allowedCommands = ['queue:work']): self
    {
        $php = (string) array_shift($cmdline);

        while ($cmdline !== [] && str_starts_with((string) $cmdline[0], '-')) {
            $option = array_shift($cmdline);

            if ($option === '-f' || $option === '--') {
                break; // the script follows
            }

            if (in_array($option, self::PHP_VALUE_OPTIONS, true)) {
                array_shift($cmdline);
            }
        }

        return self::fromArgv($php, $cmdline, $allowedCommands);
    }

    /**
     * A `queue:work` command line as the kernel recorded it: `$php` as typed and argv
     * from the script on. Validated as an array, so `--queue={x}` and other shell characters are fine.
     *
     * @param  list<string>  $argv  [script, command, args…]
     */
    public static function fromArgv(string $php, array $argv, array $allowedCommands = ['queue:work']): self
    {
        $argv = array_values(array_map('strval', $argv));

        if (count($argv) < 2 || ! in_array($argv[1], $allowedCommands, true) || $argv[0] === '' || str_starts_with($argv[0], '-')) {
            throw new InvalidArgumentException('Unexpected command line: '.implode(' ', $argv));
        }

        foreach (array_slice($argv, 2) as $word) {
            if (in_array(explode('=', $word, 2)[0], ['--once', '--stop-when-empty'], true)) {
                throw new InvalidArgumentException("{$word} runs stock");
            }
        }

        return new self($php, $argv[0], $argv, $argv[1]);
    }

    /** The absolute entry script for a working directory. */
    public function entry(string $cwd): string
    {
        return str_starts_with($this->script, '/') ? $this->script : rtrim($cwd, '/').'/'.$this->script;
    }

    /** The title `ps` shows for the stock exec of this command line (shell quotes removed). */
    public function title(): string
    {
        return $this->php.' '.implode(' ', $this->argv);
    }

    /** Value of a `--name=value` option, if present. */
    public function option(string $name): ?string
    {
        foreach ($this->argv as $word) {
            if (str_starts_with($word, "--{$name}=")) {
                return substr($word, strlen($name) + 3);
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function split(string $line): array
    {
        $words = [];
        $current = '';
        $inWord = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $c = $line[$i];

            if ($c === "'") {
                $end = strpos($line, "'", $i + 1);

                if ($end === false) {
                    throw new InvalidArgumentException('Unterminated quote in: '.$line);
                }

                $current .= substr($line, $i + 1, $end - $i - 1);
                $i = $end;
                $inWord = true;
            } elseif ($c === '"') {
                $i++;

                while ($i < $length && $line[$i] !== '"') {
                    if ($line[$i] === '\\' && $i + 1 < $length && in_array($line[$i + 1], ['"', '\\', '$', '`'], true)) {
                        $i++;
                    } elseif (in_array($line[$i], ['$', '`'], true)) {
                        throw new InvalidArgumentException('Substitution in quoted word: '.$line);
                    }

                    $current .= $line[$i++];
                }

                if ($i >= $length) {
                    throw new InvalidArgumentException('Unterminated quote in: '.$line);
                }

                $inWord = true;
            } elseif ($c === '\\' && $i + 1 < $length) {
                $current .= $line[++$i];
                $inWord = true;
            } elseif ($c === ' ' || $c === "\t") {
                if ($inWord) {
                    $words[] = $current;
                    $current = '';
                    $inWord = false;
                }
            } elseif (str_contains(";|&<>()`\$\n*?{}[]", $c)) {
                throw new InvalidArgumentException("Shell metacharacter [{$c}] in: {$line}");
            } else {
                $current .= $c;
                $inWord = true;
            }
        }

        if ($inWord) {
            $words[] = $current;
        }

        return $words;
    }
}
