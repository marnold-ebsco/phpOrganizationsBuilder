<?php declare(strict_types=1);

namespace Organizations\Tests;

use Organizations\Casting\ValueCaster;
use Organizations\Io\DelimitedFileReader;
use Organizations\Io\ErrorLog;
use Organizations\Mapping\FieldMapper;
use Organizations\RecordBuilder;
use Organizations\RecordFileImporter;
use Organizations\ReferenceRegistry;
use Organizations\Schema\OrganizationSchema;
use phpFolioClient\FolioUtils;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: wires DelimitedFileReader + RecordBuilder (configured
 * for {@see OrganizationSchema}) + ErrorLog together exactly as
 * bin/build-organizations does, against a real tab-delimited temp file
 * (the reader's default delimiter) and the actual bundled mapping file.
 *
 * The error log's open()/close() lifecycle is the *caller's*
 * responsibility (bin/build-organizations shares one log across several
 * of these calls), so these tests open it before calling import() and
 * close it before reading the file back, same as that caller does.
 */
final class RecordFileImporterTest extends TestCase {
    private string $csvPath;
    private string $logDir;

    protected function setUp(): void {
        $this->csvPath = tempnam(sys_get_temp_dir(), 'orgs') . '.tsv';
        $this->logDir = sys_get_temp_dir() . '/orgs_importer_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        if (is_file($this->csvPath)) {
            unlink($this->csvPath);
        }
        if (is_dir($this->logDir)) {
            array_map('unlink', glob($this->logDir . '/*') ?: []);
            rmdir($this->logDir);
        }
    }

    private function makeImporter(): RecordFileImporter {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/organization_field_mapping.json');
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), OrganizationSchema::class, '|', new ReferenceRegistry());
        return new RecordFileImporter($builder);
    }

    public function testValidRowsAreBuiltAndInvalidRowsAreSkippedAndLogged(): void {
        file_put_contents(
            $this->csvPath,
            "name\tcode\tstatus\n" .
            "Good Co\tGOOD\tActive\n" .
            "\tMISSINGNAME\tActive\n" .
            "Bad Status Co\tBADSTATUS\tSuspended\n"
        );

        $reader = new DelimitedFileReader($this->csvPath);
        $errorLog = new ErrorLog(ErrorLog::defaultPathFor($this->csvPath, $this->logDir));
        $errorLog->open();

        $result = $this->makeImporter()->import($reader, $errorLog);
        $errorLog->close();

        $this->assertTrue($result->hadErrors());
        $this->assertSame([
            ['name' => 'Good Co', 'code' => 'GOOD', 'status' => 'Active'],
        ], $result->getRecords());
        $this->assertSame([2], $result->getAcceptedRowNumbers());
        $this->assertSame([3, 4], $result->getRejectedRowNumbers());

        $logContents = file_get_contents($result->getErrorLogPath());
        $this->assertStringContainsString("Row 3: missing required field 'name'", $logContents);
        $this->assertStringContainsString('Row 3 skipped due to validation errors.', $logContents);
        $this->assertStringContainsString(
            "Row 4: 'status' value 'Suspended' is not one of: Active, Inactive, Pending",
            $logContents
        );
        $this->assertStringContainsString('Built 1 record(s).', $logContents);
    }

    public function testAllValidRowsProduceNoErrorsAndHadErrorsIsFalse(): void {
        file_put_contents(
            $this->csvPath,
            "name\tcode\tstatus\n" .
            "Good Co\tGOOD\tActive\n" .
            "Another Co\tANOTHER\tInactive\n"
        );

        $reader = new DelimitedFileReader($this->csvPath);
        $errorLog = new ErrorLog(ErrorLog::defaultPathFor($this->csvPath, $this->logDir));
        $errorLog->open();

        $result = $this->makeImporter()->import($reader, $errorLog);
        $errorLog->close();

        $this->assertFalse($result->hadErrors());
        $this->assertCount(2, $result->getRecords());
        $this->assertSame([2, 3], $result->getAcceptedRowNumbers());
        $this->assertSame([], $result->getRejectedRowNumbers());
        $this->assertStringContainsString('Built 2 record(s).', file_get_contents($result->getErrorLogPath()));
    }

    public function testImportWritesASectionHeaderNamedForTheGivenLabel(): void {
        file_put_contents($this->csvPath, "name\tcode\tstatus\nGood Co\tGOOD\tActive\n");

        $reader = new DelimitedFileReader($this->csvPath);
        $errorLog = new ErrorLog(ErrorLog::defaultPathFor($this->csvPath, $this->logDir));
        $errorLog->open();

        $result = $this->makeImporter()->import($reader, $errorLog, 'widgets');
        $errorLog->close();

        $this->assertFileExists($result->getErrorLogPath());
        $this->assertStringContainsString('== widgets ==', file_get_contents($result->getErrorLogPath()));
    }

    public function testMultipleImportsShareOneLogFileWithoutOverwritingEachOther(): void {
        file_put_contents($this->csvPath, "name\tcode\tstatus\nGood Co\tGOOD\tActive\n");

        $reader1 = new DelimitedFileReader($this->csvPath);
        $reader2 = new DelimitedFileReader($this->csvPath);
        $errorLog = new ErrorLog(ErrorLog::defaultPathFor($this->csvPath, $this->logDir));
        $errorLog->open();

        $this->makeImporter()->import($reader1, $errorLog, 'first');
        $result = $this->makeImporter()->import($reader2, $errorLog, 'second');
        $errorLog->close();

        $contents = file_get_contents($result->getErrorLogPath());
        $this->assertStringContainsString('== first ==', $contents);
        $this->assertStringContainsString('== second ==', $contents);
    }
}
