<?php declare(strict_types=1);

namespace Organizations\Io;

use RuntimeException;
use ZipArchive;

/**
 * Minimal, dependency-free `.xlsx` reader: an xlsx file is just a zip of
 * XML parts, so this uses PHP's built-in `ZipArchive`/`SimpleXML`
 * extensions instead of pulling in a full spreadsheet library. It reads
 * cell values only — no formulas, formatting, or styles — which is all
 * {@see \Organizations\Schema} record-building needs from a filled-out
 * template.
 *
 * Cells are returned exactly as authored: a completely blank row/column
 * region contributes nothing, so callers should expect sparse row arrays
 * (missing keys, not empty strings) for gaps.
 */
final class XlsxReader {
    /** @var ZipArchive|null */
    private $zip = null;

    /** @var list<string> Shared string table, index-addressed. */
    private array $sharedStrings = [];

    /** @var array<string, string> Sheet name => internal zip path (e.g. `xl/worksheets/sheet1.xml`). */
    private array $sheetPaths = [];

    public function __construct(private readonly string $path) {
    }

    /**
     * Open the file and read its shared-string table and sheet index.
     *
     * @throws RuntimeException If the file can't be opened, or doesn't
     *                          look like a valid xlsx package.
     */
    public function open(): void {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException("Cannot open xlsx file '{$this->path}'");
        }
        $this->zip = $zip;

        $this->sharedStrings = $this->readSharedStrings();
        $this->sheetPaths = $this->readSheetPaths();
    }

    /** @return list<string> Sheet names, in workbook order. */
    public function sheetNames(): array {
        return array_keys($this->sheetPaths);
    }

    /**
     * Read every row of one sheet.
     *
     * @param $sheetName Sheet name, matched exactly (case-sensitive).
     * @return array<int, array<int, string>> Row number (1-based) =>
     *         (column number (1-based) => cell value). Rows/columns with
     *         no content are simply absent, not empty-string.
     * @throws RuntimeException If no sheet with that name exists.
     */
    public function readSheet(string $sheetName): array {
        if (!isset($this->sheetPaths[$sheetName])) {
            throw new RuntimeException("No such sheet '$sheetName' in '{$this->path}'");
        }

        $xml = $this->loadXml($this->sheetPaths[$sheetName]);
        $rows = [];

        foreach ($xml->xpath('.//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowEl) {
            $rowNum = (int) $rowEl['r'];
            $row = [];
            foreach ($rowEl->xpath('./*[local-name()="c"]') as $cellEl) {
                $ref = (string) $cellEl['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $colNum = self::columnLetterToNumber($m[1]);
                $row[$colNum] = $this->cellValue($cellEl);
            }
            if ($row !== []) {
                $rows[$rowNum] = $row;
            }
        }

        return $rows;
    }

    /** Close the underlying zip handle. */
    public function close(): void {
        $this->zip?->close();
        $this->zip = null;
    }

    private function cellValue(\SimpleXMLElement $cellEl): string {
        $type = (string) ($cellEl['t'] ?? '');
        if ($type === 'inlineStr') {
            $isEl = $cellEl->xpath('./*[local-name()="is"]');
            return $isEl !== [] ? (string) $isEl[0]->asXML() && false ? '' : (string) ($isEl[0]->t ?? implode('', (array) $isEl[0])) : '';
        }

        $vEl = $cellEl->xpath('./*[local-name()="v"]');
        $raw = $vEl !== [] ? (string) $vEl[0] : '';

        if ($type === 's') {
            $index = (int) $raw;
            return $this->sharedStrings[$index] ?? '';
        }
        if ($type === 'b') {
            return $raw === '1' ? 'true' : 'false';
        }

        return $raw;
    }

    /** @return list<string> */
    private function readSharedStrings(): array {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $xml = $this->loadXml('xl/sharedStrings.xml');
        $strings = [];
        foreach ($xml->xpath('./*[local-name()="si"]') as $siEl) {
            // A shared string can be a single <t>, or multiple <r><t> runs (rich text) to concatenate.
            $tEls = $siEl->xpath('.//*[local-name()="t"]');
            $strings[] = implode('', array_map(static fn($t) => (string) $t, $tEls));
        }
        return $strings;
    }

    /** @return array<string, string> Sheet name => internal zip path. */
    private function readSheetPaths(): array {
        $workbook = $this->loadXml('xl/workbook.xml');
        $rels = $this->loadXml('xl/_rels/workbook.xml.rels');

        $targetByRelId = [];
        foreach ($rels->xpath('./*[local-name()="Relationship"]') as $relEl) {
            $targetByRelId[(string) $relEl['Id']] = (string) $relEl['Target'];
        }

        $paths = [];
        foreach ($workbook->xpath('.//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheetEl) {
            $name = (string) $sheetEl['name'];
            $relId = (string) $sheetEl->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $targetByRelId[$relId] ?? null;
            if ($target !== null) {
                // A Target starting with "/" is absolute from the package
                // root (already includes "xl/"); otherwise it's relative
                // to the xl/ folder this rels file itself lives in.
                $paths[$name] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
        }
        return $paths;
    }

    private function loadXml(string $zipEntryPath): \SimpleXMLElement {
        $contents = $this->zip->getFromName($zipEntryPath);
        if ($contents === false) {
            throw new RuntimeException("Missing '$zipEntryPath' inside '{$this->path}' — not a valid xlsx file?");
        }
        $xml = simplexml_load_string($contents);
        if ($xml === false) {
            throw new RuntimeException("Cannot parse XML in '$zipEntryPath' inside '{$this->path}'");
        }
        return $xml;
    }

    /** Convert a column letter (`A`, `Z`, `AA`, ...) to a 1-based column number. */
    private static function columnLetterToNumber(string $letters): int {
        $number = 0;
        foreach (str_split($letters) as $char) {
            $number = $number * 26 + (ord($char) - ord('A') + 1);
        }
        return $number;
    }
}
