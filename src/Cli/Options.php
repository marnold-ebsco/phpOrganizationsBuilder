<?php declare(strict_types=1);

namespace Organizations\Cli;

/**
 * Parses `--key=value`/`--flag` style CLI arguments into an associative
 * array, and resolves a few human-friendly delimiter names (`tab`,
 * `pipe`, `semicolon`, `comma`) to their literal characters.
 */
final class Options {
    /**
     * @param $argv The raw `$argv` array (index 0, the script path, is skipped).
     * @return Options keyed by name (without the leading `--`); a bare
     *         `--flag` (no `=value`) maps to `true`.
     */
    public static function parse(array $argv): array {
        $options = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
            } else {
                $key = $arg;
                $value = true;
            }
            $options[$key] = $value;
        }
        return $options;
    }

    /**
     * Resolve a delimiter option value, accepting either a literal
     * character or one of the convenience names "tab", "pipe",
     * "semicolon", "comma".
     */
    public static function resolveDelimiter(string $raw): string {
        return match (strtolower($raw)) {
            'tab' => "\t",
            'pipe' => '|',
            'semicolon' => ';',
            'comma' => ',',
            default => $raw,
        };
    }
}
