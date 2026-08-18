<?php declare(strict_types=1);

namespace Organizations\Tests;

use Organizations\Io\XlsxReader;
use Organizations\TemplateFlattener;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see TemplateFlattener} against a small synthetic template
 * fixture (tests/fixtures/template_fixture.xlsx) covering one richly-
 * populated organization ("ACME", with extras on every child sheet) and
 * one minimal one ("SOLO", nothing but the required Main Org record fields).
 */
final class TemplateFlattenerTest extends TestCase {
    private array $rows;
    private TemplateFlattener $flattener;

    protected function setUp(): void {
        $reader = new XlsxReader(dirname(__DIR__) . '/tests/fixtures/template_fixture.xlsx');
        $reader->open();
        $this->flattener = new TemplateFlattener();
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

    public function testMainOrgRecordFieldsMapToExpectedLegacyColumns(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Acme Co', $acme['name']);
        $this->assertSame('AcmeAlias', $acme['alias_value']);
        $this->assertSame('Yes', $acme['isVendor']);
        $this->assertSame('Active', $acme['status']);
        $this->assertSame('A test vendor', $acme['description']);
        $this->assertSame('1 Main St', $acme['address_addressLine1']);
        $this->assertSame('Springfield', $acme['address_city']);
        $this->assertSame('IL', $acme['address_stateRegion']);
        $this->assertSame('62701', $acme['address_zipCode']);
        $this->assertSame('USA', $acme['address_country']);
        $this->assertSame('555-1000', $acme['phone_phoneNumber']);
        $this->assertSame('info@acme.example', $acme['email_value']);
        $this->assertSame('https://acme.example', $acme['url_value']);
        $this->assertSame('Main website', $acme['url_description']);
        $this->assertSame('Support', $acme['url_categories']);
    }

    public function testFaxBecomesSecondPhoneInstanceTypedFax(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('555-2000', $acme['phone2_phoneNumber']);
        $this->assertSame('Fax', $acme['phone2_type']);
    }

    public function testAltNamesSheetFillsAliasesStartingAtTwo(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Acme Corp', $acme['alias2_value']);
        $this->assertSame('Formal name', $acme['alias2_description']);
        $this->assertSame('Acme Inc', $acme['alias3_value']);
        $this->assertArrayNotHasKey('alias3_description', $acme);
    }

    public function testAddressesSheetFillsSecondAddressWithCategory(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('2 Warehouse Rd', $acme['address2_addressLine1']);
        $this->assertSame('Billing', $acme['address2_categories']);
    }

    public function testPhonesSheetFillsThirdPhoneInstance(): void {
        $acme = $this->rowFor('ACME');

        // instance 1 = Main Org record PHONE, 2 = FAX, so the Phones sheet's
        // one extra row must land on instance 3, not 2.
        $this->assertSame('555-3000', $acme['phone3_phoneNumber']);
        $this->assertSame('Mobile', $acme['phone3_type']);
    }

    public function testEmailsAndUrlsSheetsStartAtInstanceTwo(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('sales@acme.example', $acme['email2_value']);
        $this->assertSame('https://support.acme.example', $acme['url2_value']);
    }

    public function testContactPeopleSheetMapsToContactOneWithTitleDropped(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Jo', $acme['contact1_firstName']);
        $this->assertSame('Smith', $acme['contact1_lastName']);
        $this->assertSame('a note', $acme['contact1_notes']);
        $this->assertSame('jo@acme.example', $acme['contact1_email']);
        $this->assertSame('main contact', $acme['contact1_emailDescription']);
        $this->assertSame('555-4000', $acme['contact1_phone']);
        $this->assertArrayNotHasKey('contact1_title', $acme);
    }

    public function testInterfacesSheetMapsToInterfaceOneIncludingTypeAndCredentials(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Acme Admin', $acme['interface1_name']);
        $this->assertSame('Admin', $acme['interface1_type']);
        $this->assertSame('https://admin.acme.example', $acme['interface1_uri']);
        $this->assertSame('iface note', $acme['interface1_notes']);
        $this->assertSame('user', $acme['interface1_username']);
        $this->assertSame('pass', $acme['interface1_password']);
        $this->assertArrayNotHasKey('interface1_description', $acme);
    }

    public function testVendorInfoSheetMapsToTopLevelFields(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('EFT', $acme['paymentMethod']);
        $this->assertSame('USD', $acme['vendorCurrencies']);
        $this->assertSame('30', $acme['claimingInterval']);
        $this->assertSame('Yes', $acme['exportToAccounting']);
        $this->assertSame('12-345', $acme['taxId']);
        $this->assertSame('No', $acme['liableForVat']);
    }

    public function testAccountsSheetMapsToAccountOne(): void {
        $acme = $this->rowFor('ACME');

        $this->assertSame('Main Account', $acme['account1_name']);
        $this->assertSame('999', $acme['account1_accountNo']);
        $this->assertSame('SYS1', $acme['account1_appSystemNo']);
        $this->assertSame('Active', $acme['account1_accountStatus']);
        $this->assertSame('acct note', $acme['account1_notes']);
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
