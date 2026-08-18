<?php declare(strict_types=1);

namespace Organizations\Io;

use Generator;
use RuntimeException;

/**
 * Reads a delimited file (CSV/TSV/pipe-delimited/etc.) with a header row,
 * yielding each subsequent data row as an associative array keyed by
 * lowercased, trimmed header name. Blank lines and fully-blank rows are
 * skipped silently.
 */
final class DelimitedFileReader {
    /** @var resource|null */
    private $handle = null;

    /** @var string[] Lowercased, trimmed header names, indexed by column position. */
    private array $headers = [];

    /**
     * @param $path      Path to the delimited file.
     * @param $delimiter Field delimiter (must be exactly one character).
     *                   Defaults to tab, the normal delimiter for input files.
     * @param $enclosure Field quote/enclosure character (must be exactly one character).
     */
    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = "\t",
        private readonly string $enclosure = '"',
    ) {
    }

    /**
     * Open the file and read its header row.
     *
     * @throws RuntimeException If the file can't be opened, or is empty.
     */
    public function open(): void {
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open input file '{$this->path}'");
        }
        $this->handle = $handle;

        $headerRow = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, '');
        if ($headerRow === false || $headerRow === null) {
            $this->close();
            throw new RuntimeException("Input file '{$this->path}' is empty");
        }
        $this->headers = array_map(static fn($h) => strtolower(trim((string) $h)), $headerRow);
    }

    /**
     * Iterate the file's data rows. Must be called after {@see open()}.
     *
     * @return Generator<int, array<string, mixed>> Yields `rowNum => rowAssoc`
     *         pairs (row 1 is the header, so the first yielded row is 2).
     */
    public function rows(): Generator {
        $rowNum = 1;
        while (($row = fgetcsv($this->handle, 0, $this->delimiter, $this->enclosure, '')) !== false) {
            $rowNum++;
            if ($row === [null]) {
                continue; // blank line
            }
            if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue; // fully blank row
            }

            $rowAssoc = [];
            foreach ($this->headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $rowAssoc[$header] = $row[$i] ?? null;
            }
            yield $rowNum => $rowAssoc;
        }
    }

    /** Close the underlying file handle, if open. */
    public function close(): void {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
