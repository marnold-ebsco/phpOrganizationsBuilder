<?php declare(strict_types=1);

namespace Organizations\Tests\Io;

use Organizations\Io\DelimitedFileReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DelimitedFileReaderTest extends TestCase {
    private string $path;

    protected function setUp(): void {
        $this->path = tempnam(sys_get_temp_dir(), 'orgs') . '.csv';
    }

    protected function tearDown(): void {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function writeFile(string $content): void {
        file_put_contents($this->path, $content);
    }

    public function testReadsRowsKeyedByLowercasedHeadersUsingDefaultTabDelimiter(): void {
        $this->writeFile("Name\tCode\tStatus\nAcme\tACME\tActive\n");

        $reader = new DelimitedFileReader($this->path);
        $reader->open();
        $rows = iterator_to_array($reader->rows());
        $reader->close();

        $this->assertSame([2 => ['name' => 'Acme', 'code' => 'ACME', 'status' => 'Active']], $rows);
    }

    public function testRowNumbersAccountForHeaderRow(): void {
        $this->writeFile("Name\nFirst\nSecond\n");

        $reader = new DelimitedFileReader($this->path);
        $reader->open();
        $rowNumbers = array_keys(iterator_to_array($reader->rows()));
        $reader->close();

        $this->assertSame([2, 3], $rowNumbers);
    }

    public function testSkipsBlankLines(): void {
        $this->writeFile("Name\nFirst\n\nSecond\n");

        $reader = new DelimitedFileReader($this->path);
        $reader->open();
        $rows = iterator_to_array($reader->rows());
        $reader->close();

        $this->assertCount(2, $rows);
    }

    public function testSkipsFullyBlankRows(): void {
        $this->writeFile("Name\tCode\nFirst\tONE\n\t\nSecond\tTWO\n");

        $reader = new DelimitedFileReader($this->path);
        $reader->open();
        $rows = iterator_to_array($reader->rows());
        $reader->close();

        $this->assertCount(2, $rows);
    }

    public function testSupportsOverridingTheDefaultDelimiter(): void {
        $this->writeFile("Name,Code\nAcme,ACME\n");

        $reader = new DelimitedFileReader($this->path, ',');
        $reader->open();
        $rows = iterator_to_array($reader->rows());
        $reader->close();

        $this->assertSame([2 => ['name' => 'Acme', 'code' => 'ACME']], $rows);
    }

    public function testOpenThrowsForMissingFile(): void {
        $this->expectException(RuntimeException::class);
        (new DelimitedFileReader('/no/such/file.csv'))->open();
    }

    public function testOpenThrowsForEmptyFile(): void {
        $this->writeFile('');

        $this->expectException(RuntimeException::class);
        (new DelimitedFileReader($this->path))->open();
    }
}
