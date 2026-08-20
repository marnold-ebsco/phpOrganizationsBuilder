<?php declare(strict_types=1);

namespace Organizations\Tests\Io;

use Organizations\Io\XlsxReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class XlsxReaderTest extends TestCase {
    private XlsxReader $reader;

    protected function setUp(): void {
        $this->reader = new XlsxReader(dirname(__DIR__) . '/fixtures/sample.xlsx');
        $this->reader->open();
    }

    protected function tearDown(): void {
        $this->reader->close();
    }

    public function testSheetNamesReturnsAllSheetsInOrder(): void {
        $this->assertSame(['People', 'Notes'], $this->reader->sheetNames());
    }

    public function testReadSheetReturnsHeaderAndDataRows(): void {
        $rows = $this->reader->readSheet('People');

        $this->assertSame(['Name', 'Age', 'Active'], array_values($rows[1]));
        $this->assertSame('Ada Lovelace', $rows[2][1]);
        $this->assertSame('36', $rows[2][2]);
        $this->assertSame('true', $rows[2][3]);
        $this->assertSame('Grace Hopper', $rows[3][1]);
        $this->assertSame('false', $rows[3][3]);
    }

    public function testReadSheetOmitsCompletelyBlankRows(): void {
        $rows = $this->reader->readSheet('People');

        // Row 4 (D4 only, a rich-text cell used by another test) plus
        // rows 1-3 have content; nothing else should appear.
        $this->assertSame([1, 2, 3, 4], array_keys($rows));
    }

    public function testReadSheetHandlesSparseColumnsBeyondColumnZ(): void {
        $rows = $this->reader->readSheet('Notes');

        // 'B2' -> row 2, column 2; row 1 and column 1 are simply absent.
        $this->assertArrayNotHasKey(1, $rows);
        $this->assertArrayNotHasKey(1, $rows[2]);
        $this->assertSame('sparse note', $rows[2][2]);
    }

    public function testReadSheetConcatenatesMultiRunRichTextCells(): void {
        $rows = $this->reader->readSheet('People');

        // 'D4' holds a rich-text cell with two differently-styled runs
        // ("Required" + a separately-colored "*") -- both must come back
        // concatenated into one string, not just the first run or a
        // stray "Array" from mishandling the multiple <r> children.
        $this->assertSame('Required*', $rows[4][4]);
    }

    public function testReadSheetThrowsForUnknownSheetName(): void {
        $this->expectException(RuntimeException::class);
        $this->reader->readSheet('No Such Sheet');
    }

    public function testOpenThrowsForMissingFile(): void {
        $this->expectException(RuntimeException::class);
        (new XlsxReader('/no/such/file.xlsx'))->open();
    }
}
