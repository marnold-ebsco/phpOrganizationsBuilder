<?php declare(strict_types=1);

namespace Organizations\Tests\Casting;

use Organizations\Casting\ValueCaster;
use PHPUnit\Framework\TestCase;

final class ValueCasterTest extends TestCase {
    private ValueCaster $caster;

    protected function setUp(): void {
        $this->caster = new ValueCaster();
    }

    /** @dataProvider boolProvider */
    public function testParseBoolRecognizesCommonSpellings(string $raw, ?bool $expected): void {
        $this->assertSame($expected, $this->caster->parseBool($raw));
    }

    public static function boolProvider(): array {
        return [
            ['true', true],
            ['TRUE', true],
            ['t', true],
            ['1', true],
            ['yes', true],
            ['y', true],
            ['false', false],
            ['f', false],
            ['0', false],
            ['no', false],
            ['n', false],
            ['maybe', null],
            ['', null],
        ];
    }

    public function testCastStringPassesThroughVerbatimAfterTrim(): void {
        $errors = [];
        $this->assertSame('hello', $this->caster->cast('  hello  ', 'string', 'field', $errors, 1));
        $this->assertSame([], $errors);
    }

    public function testCastBoolValidValue(): void {
        $errors = [];
        $this->assertTrue($this->caster->cast('yes', 'bool', 'isVendor', $errors, 2));
        $this->assertSame([], $errors);
    }

    public function testCastBoolInvalidValueRecordsError(): void {
        $errors = [];
        $this->assertNull($this->caster->cast('maybe', 'bool', 'isVendor', $errors, 2));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Row 2: 'isVendor' value 'maybe' is not a recognized boolean", $errors[0]);
    }

    public function testCastIntValidValue(): void {
        $errors = [];
        $this->assertSame(30, $this->caster->cast('30', 'int', 'claimingInterval', $errors, 3));
        $this->assertSame([], $errors);
    }

    public function testCastIntInvalidValueRecordsError(): void {
        $errors = [];
        $this->assertNull($this->caster->cast('thirty', 'int', 'claimingInterval', $errors, 3));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("is not a valid integer", $errors[0]);
    }

    public function testCastNumberValidValue(): void {
        $errors = [];
        $this->assertSame(12.5, $this->caster->cast('12.5', 'number', 'discountPercent', $errors, 4));
        $this->assertSame([], $errors);
    }

    public function testCastNumberInvalidValueRecordsError(): void {
        $errors = [];
        $this->assertNull($this->caster->cast('abc', 'number', 'discountPercent', $errors, 4));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is not a valid number', $errors[0]);
    }

    public function testSplitListTrimsAndDropsEmptyItems(): void {
        $this->assertSame(
            ['USD', 'EUR'],
            $this->caster->splitList(' USD | | EUR ', '|')
        );
    }

    public function testSplitListReturnsEmptyArrayForBlankCell(): void {
        $this->assertSame([], $this->caster->splitList('', '|'));
    }
}
