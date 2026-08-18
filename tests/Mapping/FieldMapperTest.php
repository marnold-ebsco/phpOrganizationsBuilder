<?php declare(strict_types=1);

namespace Organizations\Tests\Mapping;

use Organizations\Mapping\FieldMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FieldMapperTest extends TestCase {
    public function testHardcodedValueTakesPrecedenceOverRowData(): void {
        $mapper = new FieldMapper([
            'status' => ['folio_field' => 'status', 'legacy_field' => 'Status', 'value' => 'Active', 'fallback_legacy_field' => ''],
        ]);

        $this->assertSame('Active', $mapper->resolve('status', ['status' => 'Pending']));
    }

    public function testLegacyFieldIsUsedWhenNoHardcodedValue(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'Vendor Name', 'value' => '', 'fallback_legacy_field' => ''],
        ]);

        $this->assertSame('Acme Co', $mapper->resolve('name', ['vendor name' => 'Acme Co']));
    }

    public function testFallbackLegacyFieldIsUsedWhenLegacyFieldEmpty(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'Vendor Name', 'value' => '', 'fallback_legacy_field' => 'Company'],
        ]);

        $row = ['vendor name' => '', 'company' => 'Fallback Co'];
        $this->assertSame('Fallback Co', $mapper->resolve('name', $row));
    }

    public function testFallbackLegacyFieldIsUsedWhenLegacyFieldMissingFromRow(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'Vendor Name', 'value' => '', 'fallback_legacy_field' => 'Company'],
        ]);

        $row = ['company' => 'Fallback Co'];
        $this->assertSame('Fallback Co', $mapper->resolve('name', $row));
    }

    public function testNotMappedLegacyFieldIsTreatedAsAbsent(): void {
        $mapper = new FieldMapper([
            'status' => ['folio_field' => 'status', 'legacy_field' => 'Not mapped', 'value' => '', 'fallback_legacy_field' => ''],
        ]);

        $this->assertNull($mapper->resolve('status', ['status' => 'Active']));
    }

    public function testUnmappedFolioFieldResolvesToNull(): void {
        $mapper = new FieldMapper([]);

        $this->assertNull($mapper->resolve('anything', ['anything' => 'value']));
    }

    public function testFolioFieldMatchingIsCaseInsensitive(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'Name', 'legacy_field' => 'name', 'value' => '', 'fallback_legacy_field' => ''],
        ]);

        $this->assertSame('Acme', $mapper->resolve('NAME', ['name' => 'Acme']));
    }

    public function testFromFileLoadsAndIndexesEntries(): void {
        $path = tempnam(sys_get_temp_dir(), 'mapping');
        file_put_contents($path, json_encode([
            'data' => [
                ['folio_field' => 'name', 'legacy_field' => 'Vendor Name', 'value' => '', 'fallback_legacy_field' => ''],
            ],
        ]));

        try {
            $mapper = FieldMapper::fromFile($path);
            $this->assertSame('Acme', $mapper->resolve('name', ['vendor name' => 'Acme']));
        } finally {
            unlink($path);
        }
    }

    public function testFromFileThrowsForUnreadablePath(): void {
        $this->expectException(RuntimeException::class);
        FieldMapper::fromFile('/no/such/mapping.json');
    }

    public function testFromFileThrowsForMalformedJson(): void {
        $path = tempnam(sys_get_temp_dir(), 'mapping');
        file_put_contents($path, '{"not_data": []}');

        try {
            $this->expectException(RuntimeException::class);
            FieldMapper::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testBracketlessNestedKeyIsTreatedAsInstanceOne(): void {
        $mapper = new FieldMapper([
            'addresses.city' => ['folio_field' => 'addresses.city', 'legacy_field' => 'city'],
        ]);

        $this->assertSame('Boston', $mapper->resolve('addresses[1].city', ['city' => 'Boston']));
    }

    public function testExplicitInstanceOneKeyIsEquivalentToBracketless(): void {
        $mapper = new FieldMapper([
            'addresses[1].city' => ['folio_field' => 'addresses[1].city', 'legacy_field' => 'city'],
        ]);

        $this->assertSame('Boston', $mapper->resolve('addresses.city', ['city' => 'Boston']));
    }

    public function testIndicesForFindsAllDefinedInstances(): void {
        $mapper = new FieldMapper([
            'addresses.city' => ['folio_field' => 'addresses.city', 'legacy_field' => 'city1'],
            'addresses[3].city' => ['folio_field' => 'addresses[3].city', 'legacy_field' => 'city3'],
        ]);

        $this->assertSame([1, 3], $mapper->indicesFor('addresses'));
    }

    public function testIndicesForReturnsEmptyArrayWhenGroupIsUnmapped(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'name'],
        ]);

        $this->assertSame([], $mapper->indicesFor('addresses'));
    }

    public function testIndicesForIsCaseInsensitiveOnSchemaKeyArgument(): void {
        // Constructor keys are always pre-lowercased (fromFile() does this;
        // callers building an index directly must too) — only the $schemaKey
        // argument to indicesFor() itself needs case-insensitive matching.
        $mapper = new FieldMapper([
            'phonenumbers.phonenumber' => ['folio_field' => 'phoneNumbers.phoneNumber', 'legacy_field' => 'phone'],
        ]);

        $this->assertSame([1], $mapper->indicesFor('PhoneNumbers'));
    }

    public function testIndicesForDoesNotConfuseGroupsWithSharedPrefix(): void {
        $mapper = new FieldMapper([
            'addresses.city' => ['folio_field' => 'addresses.city', 'legacy_field' => 'city'],
            'addressesextra.city' => ['folio_field' => 'addressesextra.city', 'legacy_field' => 'city2'],
        ]);

        $this->assertSame([1], $mapper->indicesFor('addresses'));
    }

    public function testForInstanceStripsPrefixSoBareFieldNamesResolve(): void {
        $mapper = new FieldMapper([
            'contacts[1].firstname' => ['folio_field' => 'contacts[1].firstName', 'legacy_field' => 'c1_first'],
            'contacts[2].firstname' => ['folio_field' => 'contacts[2].firstName', 'legacy_field' => 'c2_first'],
        ]);

        $first = $mapper->forInstance('contacts', 1);
        $second = $mapper->forInstance('contacts', 2);

        $row = ['c1_first' => 'Jan', 'c2_first' => 'Mario'];
        $this->assertSame('Jan', $first->resolve('firstName', $row));
        $this->assertSame('Mario', $second->resolve('firstName', $row));
    }

    public function testForInstanceStripsPrefixFromNestedGroupKeysToo(): void {
        $mapper = new FieldMapper([
            'contacts[1].emails.value' => ['folio_field' => 'contacts[1].emails.value', 'legacy_field' => 'c1_email'],
        ]);

        $sub = $mapper->forInstance('contacts', 1);

        $this->assertSame('jan@example.com', $sub->resolve('emails.value', ['c1_email' => 'jan@example.com']));
        $this->assertSame([1], $sub->indicesFor('emails'));
    }

    public function testForInstanceOnlyIncludesMatchingInstance(): void {
        $mapper = new FieldMapper([
            'contacts[1].firstname' => ['folio_field' => 'contacts[1].firstName', 'legacy_field' => 'c1_first'],
            'contacts[2].firstname' => ['folio_field' => 'contacts[2].firstName', 'legacy_field' => 'c2_first'],
            'name' => ['folio_field' => 'name', 'legacy_field' => 'orgname'],
        ]);

        $sub = $mapper->forInstance('contacts', 1);

        $row = ['c1_first' => 'Jan', 'c2_first' => 'Mario', 'orgname' => 'EBSCO'];
        $this->assertNull($sub->resolve('name', $row));
        $this->assertNull($sub->resolve('firstName', ['c2_first' => 'Mario']));
    }
}
