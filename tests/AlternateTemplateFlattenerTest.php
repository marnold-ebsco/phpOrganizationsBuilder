<?php declare(strict_types=1);

namespace Organizations\Tests;

use Organizations\AlternateTemplateFlattener;
use Organizations\Io\XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see AlternateTemplateFlattener} against a small synthetic
 * fixture (tests/fixtures/template_fixture_alt.xlsx) covering one
 * richly-populated organization ("ACME", with data on every sheet) and
 * one minimal one ("SOLO", nothing but the required Main Org record
 * fields) - the alternate-layout counterpart of
 * tests/fixtures/template_fixture.xlsx, with the same ACME/SOLO data
 * where the two layouts overlap, so the two test classes read as
 * parallel proof that both flatteners produce the same legacy-field
 * shape from equivalent input.
 */
final class AlternateTemplateFlattenerTest extends TestCase {
    private array $rows;
    private AlternateTemplateFlattener $flattener;

    protected function setUp(): void {
        $reader = new XlsxReader(dirname(__DIR__) . '/tests/fixtures/template_fixture_alt.xlsx');
        $reader->open();
        $this->flattener = new AlternateTemplateFlattener();
        $this->rows = $this->flattener->flatten($reader);
        $reader->close();
    }

    private function rowFor(string $code): array {
        foreach ($this->rows as $row) {
            if (($row['code'] ?? null) === $code) {
                return $row;
            }
        }
        $this->fail("No flattened row for code '$code'");
    }

    public function testFindsBothOrganizations(): void {
        $this->assertCount(2, $this->rows);
    }

    public function testMainOrgRecordHoldsOnlyNonRepeatableFields(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Acme Co', $acme['name']);
        $this->assertSame('Yes', $acme['isVendor']);
        $this->assertSame('Active', $acme['status']);
        $this->assertSame('A test vendor', $acme['description']);
    }

    public function testAltNamesSheetFillsAliasesStartingAtOne(): void {
        $acme = $this->rowFor('ACME');

        // Instance 1 has no number in its legacy field name, matching
        // organization_field_mapping.json's existing bracket-less
        // convention for these five groups - see instancePrefix().
        $this->assertSame('AcmeAlias', $acme['alias_value']);
        $this->assertSame('Acme Corp', $acme['alias2_value']);
        $this->assertSame('Formal name', $acme['alias2_description']);
        $this->assertSame('Acme Inc', $acme['alias3_value']);
    }

    public function testAddressesSheetFillsBothAddressesStartingAtOneWithIsPrimary(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('1 Main St', $acme['address_addressLine1']);
        $this->assertSame('Springfield', $acme['address_city']);
        $this->assertSame('Yes', $acme['address_isPrimary']);
        $this->assertSame('2 Warehouse Rd', $acme['address2_addressLine1']);
        $this->assertSame('Billing', $acme['address2_categories']);
        $this->assertArrayNotHasKey('address2_isPrimary', $acme);
    }

    public function testPhonesSheetFillsAllThreePhonesStartingAtOne(): void {
        $acme = $this->rowFor('ACME');

        // No Main-Org-record PHONE/FAX pair to reserve instances 1-2 for
        // in this layout - a fax number is just a normal row with TYPE=Fax.
        $this->assertSame('555-1000', $acme['phone_phoneNumber']);
        $this->assertSame('Yes', $acme['phone_isPrimary']);
        $this->assertSame('555-2000', $acme['phone2_phoneNumber']);
        $this->assertSame('Fax', $acme['phone2_type']);
        $this->assertSame('555-3000', $acme['phone3_phoneNumber']);
        $this->assertSame('Mobile', $acme['phone3_type']);
    }

    public function testEmailsAndUrlsSheetsStartAtInstanceOneWithIsPrimary(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('info@acme.example', $acme['email_value']);
        $this->assertSame('Yes', $acme['email_isPrimary']);
        $this->assertSame('sales@acme.example', $acme['email2_value']);
        $this->assertArrayNotHasKey('email2_isPrimary', $acme);

        $this->assertSame('https://acme.example', $acme['url_value']);
        $this->assertSame('Main website', $acme['url_description']);
        $this->assertSame('Support', $acme['url_categories']);
        $this->assertSame('Yes', $acme['url_isPrimary']);
        $this->assertSame('https://support.acme.example', $acme['url2_value']);
    }

    public function testContactPeopleSheetMapsToContactOneWithTitleDropped(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Jo', $acme['contact1_firstName']);
        $this->assertSame('Smith', $acme['contact1_lastName']);
        $this->assertSame('a note', $acme['contact1_notes']);
        $this->assertSame('jo@acme.example', $acme['contact1_email']);
        $this->assertSame('555-4000', $acme['contact1_phone']);
        $this->assertArrayNotHasKey('contact1_title', $acme);
    }

    public function testInterfacesSheetMapsToInterfaceOneIncludingTypeAndCredentials(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Acme Admin', $acme['interface1_name']);
        $this->assertSame('Admin', $acme['interface1_type']);
        $this->assertSame('https://admin.acme.example', $acme['interface1_uri']);
        $this->assertSame('user', $acme['interface1_username']);
        $this->assertSame('pass', $acme['interface1_password']);
    }

    public function testAccountsSheetMapsToAccountOne(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Main Account', $acme['account1_name']);
        $this->assertSame('999', $acme['account1_accountNo']);
        $this->assertSame('Active', $acme['account1_accountStatus']);
    }

    public function testGetDroppedNoteCountReportsNotesSheetRows(): void {
        $this->assertSame(1, $this->flattener->getDroppedNoteCount());
    }

    public function testMinimalOrganizationHasNoExtraFields(): void {
        $solo = $this->rowFor('SOLO');

        $this->assertSame('Solo Co', $solo['name']);
        $this->assertSame('No', $solo['isVendor']);
        $this->assertArrayNotHasKey('phone_phoneNumber', $solo);
        $this->assertArrayNotHasKey('address_addressLine1', $solo);
        $this->assertArrayNotHasKey('alias_value', $solo);
    }
}
