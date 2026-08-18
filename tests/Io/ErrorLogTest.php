<?php declare(strict_types=1);

namespace Organizations\Tests\Io;

use Organizations\Io\ErrorLog;
use PHPUnit\Framework\TestCase;

final class ErrorLogTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/orgs_errorlog_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testOpenCreatesParentDirectoryAndFile(): void {
        $path = $this->dir . '/nested/errors.log';
        $log = new ErrorLog($path);

        $log->open();
        $log->write('hello');
        $log->close();

        $this->assertFileExists($path);
        $this->assertSame("hello\n", file_get_contents($path));

        unlink($path);
        rmdir($this->dir . '/nested');
    }

    public function testEachOpenOverwritesRatherThanAppends(): void {
        $path = $this->dir . '/errors.log';
        mkdir($this->dir);

        $first = new ErrorLog($path);
        $first->open();
        $first->write('first run');
        $first->close();

        $second = new ErrorLog($path);
        $second->open();
        $second->write('second run');
        $second->close();

        $this->assertSame("second run\n", file_get_contents($path));
    }

    public function testDefaultPathForIncludesInputBasenameAndIsUnique(): void {
        $pathA = ErrorLog::defaultPathFor('/data/orgs.csv', $this->dir);
        $pathB = ErrorLog::defaultPathFor('/data/orgs.csv', $this->dir);

        $this->assertStringContainsString('orgs_', basename($pathA));
        $this->assertStringEndsWith('.log', $pathA);
        $this->assertNotSame($pathA, $pathB);
    }

    public function testGetPathReturnsConstructorPath(): void {
        $log = new ErrorLog('/some/path/errors.log');
        $this->assertSame('/some/path/errors.log', $log->getPath());
    }
}
