<?php declare(strict_types=1);

namespace Organizations\Io;

use RuntimeException;

/**
 * A single run's error/validation log file. By default, every
 * {@see open()} call creates a brand-new file (never appends to an
 * existing one), creating its parent directory if necessary — pass
 * `$append = true` for the one case that's meant to continue a file a
 * separate, earlier step already started (see `process_template.php`'s
 * flattening-stage summary, continued by `bin/build-organizations`'s own
 * "Run started" section).
 */
final class ErrorLog {
    /** @var resource|null */
    private $handle = null;

    /**
     * @param $path Path of the log file to create.
     */
    public function __construct(private readonly string $path) {
    }

    /**
     * Build a fresh, collision-resistant default log path for one run,
     * named after the input file so multiple inputs' logs are easy to
     * tell apart.
     *
     * @param $inputPath Path of the file being imported.
     * @param $logsDir   Directory the log file should live in.
     * @param $suffix    Extra tag inserted before the timestamp (e.g.
     *                   `contacts`), useful when several record types
     *                   are imported from the same input file in one run.
     */
    public static function defaultPathFor(string $inputPath, string $logsDir, string $suffix = ''): string {
        $base = pathinfo($inputPath, PATHINFO_FILENAME);
        if ($suffix !== '') {
            $base .= "_{$suffix}";
        }
        $timestamp = date('Ymd_His');
        $unique = bin2hex(random_bytes(3));
        return rtrim($logsDir, '/\\') . "/{$base}_{$timestamp}_{$unique}.log";
    }

    /**
     * Create the log file (and its parent directory, if needed) — or,
     * with `$append = true`, continue writing to one that already exists.
     *
     * @param $append When true, opens in append mode instead of
     *                truncating — for continuing a file an earlier step
     *                already wrote to, rather than starting fresh.
     * @throws RuntimeException If the directory can't be created, or
     *                          the file can't be opened for writing.
     */
    public function open(bool $append = false): void {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory '$dir'");
        }
        $handle = fopen($this->path, $append ? 'a' : 'w');
        if ($handle === false) {
            throw new RuntimeException("Cannot write to error log '{$this->path}'");
        }
        $this->handle = $handle;
    }

    /** Append one line (a trailing newline is added). */
    public function write(string $line): void {
        fwrite($this->handle, $line . "\n");
    }

    /** @return The path this log was opened at. */
    public function getPath(): string {
        return $this->path;
    }

    /** Close the underlying file handle, if open. */
    public function close(): void {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
