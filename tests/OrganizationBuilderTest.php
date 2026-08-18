<?php declare(strict_types=1);

namespace Organizations\Tests;

use Organizations\Casting\ValueCaster;
use Organizations\Mapping\FieldMapper;
use Organizations\RecordBuilder;
use Organizations\ReferenceRegistry;
use Organizations\Schema\OrganizationSchema;
use phpFolioClient\FolioUtils;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see RecordBuilder} (configured for {@see OrganizationSchema})
 * against the actual organization_field_mapping.json shipped at the
 * project root, using the same flat-file column conventions as
 * organizations_sample.tsv — so these tests double as a check that the
 * bundled mapping file and the builder still agree with each other.
 */
final class OrganizationBuilderTest extends TestCase {
    private RecordBuilder $builder;
    private ReferenceRegistry $registry;

    protected function setUp(): void {
        $mapper = FieldMapper::fromFile(dirname(__DIR__) . '/organization_field_mapping.json');
        $this->registry = new ReferenceRegistry();
        $this->builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), OrganizationSchema::class, '|', $this->registry);
    }

    /** @param array<string, string> $row */
    private function build(array $row, int $rowNum = 2): array {
        $result = $this->builder->build($row, $rowNum);
        return [$result->getRecord(), $result->getErrors()];
    }

    public function testBuildsMinimalValidOrganization(): void {
        [$org, $errors] = $this->build([
            'name' => 'Friends of the Library',
            'code' => 'FOL-DONOR',
            'status' => 'Active',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame([
            'name' => 'Friends of the Library',
            'code' => 'FOL-DONOR',
            'status' => 'Active',
        ], $org);
    }

    public function testBuildsFullOrganizationWithAllNestedGroups(): void {
        [$org, $errors] = $this->build([
            'name' => 'EBSCO Information Services',
            'code' => 'EBSCO',
            'status' => 'Active',
            'isvendor' => 'true',
            'organizationtypes' => 'Subscription Agent|Publisher',
            'vendorcurrencies' => 'USD|EUR',
            'address_addressline1' => '10 Estes St',
            'address_city' => 'Ipswich',
            'phone_phonenumber' => '978-356-6500',
            'phone_type' => 'Office',
            'email_value' => 'customerservice@ebsco.com',
            'url_value' => 'https://www.ebsco.com',
            'alias_value' => 'EBSCOhost',
        ]);

        $this->assertSame([], $errors);
        $this->assertTrue($org['isVendor']);
        $this->assertCount(2, $org['organizationTypes']);
        foreach ($org['organizationTypes'] as $uuid) {
            $this->assertTrue((new FolioUtils())->isValidUuid($uuid));
        }
        $this->assertSame(
            $this->registry->resolve('organizationType', 'Subscription Agent'),
            $org['organizationTypes'][0]
        );
        $this->assertSame(
            $this->registry->resolve('organizationType', 'Publisher'),
            $org['organizationTypes'][1]
        );
        $this->assertSame(['USD', 'EUR'], $org['vendorCurrencies']);
        $this->assertSame([['addressLine1' => '10 Estes St', 'city' => 'Ipswich', 'isPrimary' => true]], $org['addresses']);
        $this->assertSame([['phoneNumber' => '978-356-6500', 'type' => 'Office', 'isPrimary' => true]], $org['phoneNumbers']);
        $this->assertSame([['value' => 'customerservice@ebsco.com', 'isPrimary' => true]], $org['emails']);
        $this->assertSame([['value' => 'https://www.ebsco.com', 'isPrimary' => true]], $org['urls']);
        $this->assertSame([['value' => 'EBSCOhost']], $org['aliases']);
    }

    public function testOmitsNestedGroupEntirelyWhenNoFieldInItIsPopulated(): void {
        [$org, $errors] = $this->build([
            'name' => 'No Address Co',
            'code' => 'NOADDR',
            'status' => 'Active',
        ]);

        $this->assertSame([], $errors);
        $this->assertArrayNotHasKey('addresses', $org);
        $this->assertArrayNotHasKey('phoneNumbers', $org);
        $this->assertArrayNotHasKey('emails', $org);
        $this->assertArrayNotHasKey('urls', $org);
        $this->assertArrayNotHasKey('aliases', $org);
    }

    public function testBundledMappingBuildsTwoAddressesAndTwoPhoneNumbers(): void {
        [$org, $errors] = $this->build([
            'name' => 'Two Locations Co',
            'code' => 'TWOLOC',
            'status' => 'Active',
            'address_city' => 'Ipswich',
            'address2_city' => 'Boston',
            'phone_phonenumber' => '555-1111',
            'phone2_phonenumber' => '555-2222',
            'phone2_type' => 'Fax',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            [
                ['city' => 'Ipswich', 'isPrimary' => true],
                ['city' => 'Boston'],
            ],
            $org['addresses']
        );
        $this->assertSame(
            [
                ['phoneNumber' => '555-1111', 'isPrimary' => true],
                ['phoneNumber' => '555-2222', 'type' => 'Fax'],
            ],
            $org['phoneNumbers']
        );
    }

    public function testBundledMappingBuildsTwoEmailsAndTwoUrls(): void {
        [$org, $errors] = $this->build([
            'name' => 'Two Contacts Co',
            'code' => 'TWOCONTACT',
            'status' => 'Active',
            'email_value' => 'info@example.com',
            'email2_value' => 'support@example.com',
            'email2_description' => 'Support inbox',
            'url_value' => 'https://example.com',
            'url2_value' => 'https://support.example.com',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            [
                ['value' => 'info@example.com', 'isPrimary' => true],
                ['value' => 'support@example.com', 'description' => 'Support inbox'],
            ],
            $org['emails']
        );
        $this->assertSame(
            [
                ['value' => 'https://example.com', 'isPrimary' => true],
                ['value' => 'https://support.example.com'],
            ],
            $org['urls']
        );
    }

    public function testBundledMappingBuildsThreeAliasesWithNoIsPrimary(): void {
        [$org, $errors] = $this->build([
            'name' => 'Many Names Co',
            'code' => 'MANYNAMES',
            'status' => 'Active',
            'alias_value' => 'Many Names Company',
            'alias2_value' => 'MNC',
            'alias3_value' => 'Many Names Co., Ltd.',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            [
                ['value' => 'Many Names Company'],
                ['value' => 'MNC'],
                ['value' => 'Many Names Co., Ltd.'],
            ],
            $org['aliases']
        );
    }

    public function testAddressCategoryNameResolvesThroughSharedRegistry(): void {
        [$org, $errors] = $this->build([
            'name' => 'Category Co',
            'code' => 'CATCO',
            'status' => 'Active',
            'address_city' => 'Ipswich',
            'address_categories' => 'Billing',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            [$this->registry->resolve('category', 'Billing')],
            $org['addresses'][0]['categories']
        );
    }

    public function testAddressCategoriesSplitOnSemicolonNotPipe(): void {
        [$org, $errors] = $this->build([
            'name' => 'Category Co',
            'code' => 'CATCO',
            'status' => 'Active',
            'address_city' => 'Ipswich',
            'address_categories' => 'Billing;Support',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            [
                $this->registry->resolve('category', 'Billing'),
                $this->registry->resolve('category', 'Support'),
            ],
            $org['addresses'][0]['categories']
        );
    }

    public function testUrlNotesFlowsThroughAsASingleStringNotAList(): void {
        [$org, $errors] = $this->build([
            'name' => 'Notes Co',
            'code' => 'NOTESCO',
            'status' => 'Active',
            'url_value' => 'https://www.example.com',
            'url_notes' => 'Requires vendor portal login credentials',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame('Requires vendor portal login credentials', $org['urls'][0]['notes']);
    }

    public function testBuildsTwoAccountsFromBundledMapping(): void {
        [$org, $errors] = $this->build([
            'name' => 'Two Accounts Co',
            'code' => 'TWOACCT',
            'status' => 'Active',
            'account1_name' => 'eBooks',
            'account1_accountno' => '111',
            'account1_accountstatus' => 'Active',
            'account2_name' => 'Databases',
            'account2_accountno' => '222',
            'account2_accountstatus' => 'Inactive',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            [
                ['name' => 'eBooks', 'accountNo' => '111', 'accountStatus' => 'Active'],
                ['name' => 'Databases', 'accountNo' => '222', 'accountStatus' => 'Inactive'],
            ],
            $org['accounts']
        );
    }

    public function testAccountMissingRequiredFieldIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Bad Account Co',
            'code' => 'BADACCT',
            'status' => 'Active',
            'account1_name' => 'eBooks',
        ]);

        $this->assertContains(
            "Row 2: 'accounts[1]' group is missing required field 'accounts[1].accountNo'",
            $errors
        );
        $this->assertContains(
            "Row 2: 'accounts[1]' group is missing required field 'accounts[1].accountStatus'",
            $errors
        );
    }

    public function testOnlySecondInstancePopulatedStillBuildsJustOne(): void {
        [$org, $errors] = $this->build([
            'name' => 'Second Address Only Co',
            'code' => 'SECONDONLY',
            'status' => 'Active',
            'address2_city' => 'Boston',
        ]);

        $this->assertSame([], $errors);
        $this->assertSame([['city' => 'Boston', 'isPrimary' => true]], $org['addresses']);
    }

    public function testExplicitIsPrimaryOnSecondInstanceIsRespected(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'name'],
            'code' => ['folio_field' => 'code', 'legacy_field' => 'code'],
            'status' => ['folio_field' => 'status', 'legacy_field' => 'status'],
            'addresses.city' => ['folio_field' => 'addresses.city', 'legacy_field' => 'city1'],
            'addresses.isprimary' => ['folio_field' => 'addresses.isPrimary', 'legacy_field' => 'primary1'],
            'addresses[2].city' => ['folio_field' => 'addresses[2].city', 'legacy_field' => 'city2'],
            'addresses[2].isprimary' => ['folio_field' => 'addresses[2].isPrimary', 'legacy_field' => 'primary2'],
        ]);
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), OrganizationSchema::class, '|');

        $result = $builder->build([
            'name' => 'Explicit Primary Co',
            'code' => 'EXPLICIT',
            'status' => 'Active',
            'city1' => 'Ipswich',
            'primary1' => 'false',
            'city2' => 'Boston',
            'primary2' => 'true',
        ], 2);

        $this->assertSame([], $result->getErrors());
        $this->assertSame(
            [
                ['city' => 'Ipswich', 'isPrimary' => false],
                ['city' => 'Boston', 'isPrimary' => true],
            ],
            $result->getRecord()['addresses']
        );
    }

    public function testFirstInstanceExplicitlyNotPrimaryDefaultsTheNextOneInstead(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'name'],
            'code' => ['folio_field' => 'code', 'legacy_field' => 'code'],
            'status' => ['folio_field' => 'status', 'legacy_field' => 'status'],
            'addresses.city' => ['folio_field' => 'addresses.city', 'legacy_field' => 'city1'],
            'addresses.isprimary' => ['folio_field' => 'addresses.isPrimary', 'legacy_field' => 'primary1'],
            'addresses[2].city' => ['folio_field' => 'addresses[2].city', 'legacy_field' => 'city2'],
            'addresses[3].city' => ['folio_field' => 'addresses[3].city', 'legacy_field' => 'city3'],
        ]);
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), OrganizationSchema::class, '|');

        // Nobody says "yes"; the first says "no" explicitly - the next
        // one (which said nothing) should become primary instead of the
        // first, and the third is left alone either way.
        $result = $builder->build([
            'name' => 'Skip First Co', 'code' => 'SKIPFIRST', 'status' => 'Active',
            'city1' => 'Ipswich', 'primary1' => 'false',
            'city2' => 'Boston',
            'city3' => 'Denver',
        ], 2);

        $this->assertSame([], $result->getErrors());
        $this->assertSame(
            [
                ['city' => 'Ipswich', 'isPrimary' => false],
                ['city' => 'Boston', 'isPrimary' => true],
                ['city' => 'Denver'],
            ],
            $result->getRecord()['addresses']
        );
    }

    public function testEveryInstanceExplicitlyNotPrimaryIsRespectedAsIs(): void {
        $mapper = new FieldMapper([
            'name' => ['folio_field' => 'name', 'legacy_field' => 'name'],
            'code' => ['folio_field' => 'code', 'legacy_field' => 'code'],
            'status' => ['folio_field' => 'status', 'legacy_field' => 'status'],
            'addresses.city' => ['folio_field' => 'addresses.city', 'legacy_field' => 'city1'],
            'addresses.isprimary' => ['folio_field' => 'addresses.isPrimary', 'legacy_field' => 'primary1'],
            'addresses[2].city' => ['folio_field' => 'addresses[2].city', 'legacy_field' => 'city2'],
            'addresses[2].isprimary' => ['folio_field' => 'addresses[2].isPrimary', 'legacy_field' => 'primary2'],
        ]);
        $builder = new RecordBuilder($mapper, new ValueCaster(), new FolioUtils(), OrganizationSchema::class, '|');

        // Every instance explicitly says "no" - none should be forced
        // to primary, since that's what was explicitly asked for.
        $result = $builder->build([
            'name' => 'All No Co', 'code' => 'ALLNO', 'status' => 'Active',
            'city1' => 'Ipswich', 'primary1' => 'false',
            'city2' => 'Boston', 'primary2' => 'false',
        ], 2);

        $this->assertSame([], $result->getErrors());
        $this->assertSame(
            [
                ['city' => 'Ipswich', 'isPrimary' => false],
                ['city' => 'Boston', 'isPrimary' => false],
            ],
            $result->getRecord()['addresses']
        );
    }

    public function testAnyInstanceCanFailValidationIndependently(): void {
        [, $errors] = $this->build([
            'name' => 'Bad Second Phone Co',
            'code' => 'BADSECONDPHONE',
            'status' => 'Active',
            'phone_phonenumber' => '555-1111',
            'phone2_type' => 'Office',
        ]);

        $this->assertContains(
            "Row 2: 'phoneNumbers[2]' group is missing required field 'phoneNumbers[2].phoneNumber'",
            $errors
        );
    }

    public function testMissingRequiredTopLevelFieldsAreReported(): void {
        [, $errors] = $this->build([
            'name' => '',
            'code' => '',
            'status' => '',
        ]);

        $this->assertContains("Row 2: missing required field 'name'", $errors);
        $this->assertContains("Row 2: missing required field 'code'", $errors);
        $this->assertContains("Row 2: missing required field 'status'", $errors);
    }

    public function testInvalidStatusEnumIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Sketchy Vendor LLC',
            'code' => 'SKETCHY',
            'status' => 'Suspended',
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(
            "'status' value 'Suspended' is not one of: Active, Inactive, Pending",
            $errors[0]
        );
    }

    public function testInvalidIdUuidIsReported(): void {
        [, $errors] = $this->build([
            'id' => 'not-a-uuid',
            'name' => 'Bad Id Co',
            'code' => 'BADID',
            'status' => 'Active',
        ]);

        $this->assertContains("Row 2: 'id' value 'not-a-uuid' is not a valid UUID", $errors);
    }

    public function testInvalidUuidInListFieldIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Bad UUID Co',
            'code' => 'BADUUID',
            'status' => 'Active',
            'acqunitids' => 'not-a-uuid',
        ]);

        $this->assertContains("Row 2: 'acqUnitIds' contains invalid UUID 'not-a-uuid'", $errors);
    }

    public function testOrganizationTypeNameResolvesToSameUuidEachTime(): void {
        [$orgA] = $this->build([
            'name' => 'First Co', 'code' => 'FIRSTCO', 'status' => 'Active',
            'organizationtypes' => 'Vendor',
        ]);
        [$orgB] = $this->build([
            'name' => 'Second Co', 'code' => 'SECONDCO', 'status' => 'Active',
            'organizationtypes' => 'Vendor',
        ], 3);

        $this->assertSame($orgA['organizationTypes'][0], $orgB['organizationTypes'][0]);
    }

    public function testInvalidBooleanIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Maybe Vendor Co',
            'code' => 'MAYBE',
            'status' => 'Active',
            'isvendor' => 'maybe',
        ]);

        $this->assertContains(
            "Row 2: 'isVendor' value 'maybe' is not a recognized boolean (true/false/yes/no/1/0)",
            $errors
        );
    }

    public function testPhoneGroupMissingRequiredFieldIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'No Phone Number Co',
            'code' => 'NOPHONE',
            'status' => 'Active',
            'phone_type' => 'Office',
        ]);

        $this->assertContains(
            "Row 2: 'phoneNumbers[1]' group is missing required field 'phoneNumbers[1].phoneNumber'",
            $errors
        );
    }

    public function testInvalidPhoneTypeEnumIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Bad Phone Type Co',
            'code' => 'BADPHONE',
            'status' => 'Active',
            'phone_phonenumber' => '555-1234',
            'phone_type' => 'Carrier Pigeon',
        ]);

        $this->assertNotEmpty(array_filter(
            $errors,
            static fn($e) => str_contains($e, "'phoneNumbers[1].type' value 'Carrier Pigeon' is not one of")
        ));
    }

    public function testInvalidUrlPatternIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Bad Url Co',
            'code' => 'BADURL',
            'status' => 'Active',
            'url_value' => 'not a url',
        ]);

        $this->assertNotEmpty(array_filter(
            $errors,
            static fn($e) => str_contains($e, "'urls[1].value' value 'not a url' does not look like a valid URL")
        ));
    }

    public function testBlankCodeIsReported(): void {
        [, $errors] = $this->build([
            'name' => 'Blank Code Co',
            'code' => '   ',
            'status' => 'Active',
        ]);

        $this->assertContains("Row 2: missing required field 'code'", $errors);
    }
}
