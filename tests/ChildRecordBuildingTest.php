<?php declare(strict_types=1);

namespace Organizations\Tests;

use Organizations\Casting\ValueCaster;
use Organizations\Mapping\FieldMapper;
use Organizations\RecordBuilder;
use Organizations\ReferenceRegistry;
use Organizations\Schema\ContactSchema;
use Organizations\Schema\InterfaceCredentialSchema;
use Organizations\Schema\InterfaceSchema;
use Organizations\Schema\NoteSchema;
use phpFolioClient\FolioUtils;
use PHPUnit\Framework\TestCase;

/**
 * Exercises building contact, interface, and note records — the "0+ per
 * row" pattern bin/build-organizations uses via
 * {@see FieldMapper::forInstance()} — against the actual bundled
 * organization_field_mapping.json's `contacts[N].*`/`interfaces[N].*`/
 * `notes[N].*` entries, the same way buildChildRecords()/buildNotes()
 * in bin/build-organizations do. `domain` and `links` — the two
 * {@see NoteSchema} fields bin/build-organizations assembles itself
 * after {@see RecordBuilder::build()} returns, rather than mapping them
 * — are deliberately out of scope here; see NoteSchema's docblock.
 */
final class ChildRecordBuildingTest extends TestCase {
    private FieldMapper $mapper;
    private ValueCaster $caster;
    private FolioUtils $folioUtils;
    private ReferenceRegistry $registry;

    protected function setUp(): void {
        $this->mapper = FieldMapper::fromFile(dirname(__DIR__) . '/organization_field_mapping.json');
        $this->caster = new ValueCaster();
        $this->folioUtils = new FolioUtils();
        $this->registry = new ReferenceRegistry();
    }

    /** @return list<array> built (non-empty) records for every instance of $schemaKey */
    private function buildAll(string $schemaKey, string $schemaClass, array $row, int $rowNum = 2): array {
        $records = [];
        foreach ($this->mapper->indicesFor($schemaKey) as $index) {
            $subMapper = $this->mapper->forInstance($schemaKey, $index);
            $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, $schemaClass, '|', $this->registry);
            $result = $builder->build($row, $rowNum);
            if (!empty($result->getRecord())) {
                $records[] = $result->getRecord();
            }
        }
        return $records;
    }

    public function testBuildsTwoContactsFromOneRow(): void {
        $contacts = $this->buildAll('contacts', ContactSchema::class, [
            'contact1_firstname' => 'Jan',
            'contact1_lastname' => 'Novak',
            'contact1_email' => 'jnovak@ebsco.com',
            'contact1_phone' => '555-201-0001',
            'contact2_firstname' => 'Mario',
            'contact2_lastname' => 'Rossi',
            'contact2_email' => 'mrossi@ebsco.com',
        ]);

        $this->assertCount(2, $contacts);
        $this->assertSame('Jan', $contacts[0]['firstName']);
        $this->assertSame('Novak', $contacts[0]['lastName']);
        $this->assertSame([['value' => 'jnovak@ebsco.com', 'isPrimary' => true]], $contacts[0]['emails']);
        $this->assertSame([['phoneNumber' => '555-201-0001', 'isPrimary' => true]], $contacts[0]['phoneNumbers']);
        $this->assertSame('Mario', $contacts[1]['firstName']);
        $this->assertArrayNotHasKey('phoneNumbers', $contacts[1]);
    }

    public function testContactMissingRequiredLastNameIsReportedAndOmitted(): void {
        $subMapper = $this->mapper->forInstance('contacts', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, ContactSchema::class, '|', $this->registry);

        $result = $builder->build(['contact1_firstname' => 'Jan'], 2);

        $this->assertContains("Row 2: missing required field 'lastName'", $result->getErrors());
    }

    public function testRowWithNoContactDataBuildsNoContacts(): void {
        $contacts = $this->buildAll('contacts', ContactSchema::class, [
            'name' => 'No Contacts Co',
        ]);

        $this->assertSame([], $contacts);
    }

    public function testContactCategoryNameResolvesThroughSharedRegistry(): void {
        $contacts = $this->buildAll('contacts', ContactSchema::class, [
            'contact1_firstname' => 'Jan',
            'contact1_lastname' => 'Novak',
            'contact1_categories' => 'Renewals',
        ]);

        $this->assertSame($this->registry->resolve('category', 'Renewals'), $contacts[0]['categories'][0]);
    }

    public function testBuildsTwoInterfacesFromOneRow(): void {
        $interfaces = $this->buildAll('interfaces', InterfaceSchema::class, [
            'interface1_name' => 'EBSCO Admin',
            'interface1_uri' => 'https://eadmin.ebscohost.com/eadmin',
            'interface1_deliverymethod' => 'Online',
            'interface2_name' => 'EBSCONET',
            'interface2_uri' => 'https://www.ebsconet.com',
        ]);

        $this->assertCount(2, $interfaces);
        $this->assertSame('EBSCO Admin', $interfaces[0]['name']);
        $this->assertSame('Online', $interfaces[0]['deliveryMethod']);
        $this->assertSame('EBSCONET', $interfaces[1]['name']);
    }

    public function testInterfaceTypeBuildsAsAnArrayOfValidValues(): void {
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceSchema::class, '|', $this->registry);

        $result = $builder->build([
            'interface1_name' => 'EBSCO Admin',
            'interface1_type' => 'Admin|Reports',
        ], 2);

        $this->assertSame([], $result->getErrors());
        $this->assertSame(['Admin', 'Reports'], $result->getRecord()['type']);
    }

    public function testInterfaceInvalidTypeIsReported(): void {
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceSchema::class, '|', $this->registry);

        $result = $builder->build([
            'interface1_name' => 'Bad Interface',
            'interface1_type' => 'Sales',
        ], 2);

        $this->assertNotEmpty(array_filter(
            $result->getErrors(),
            static fn($e) => str_contains($e, "'type' contains 'Sales', which is not one of")
        ));
    }

    public function testInterfaceInvalidDeliveryMethodIsReported(): void {
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceSchema::class, '|', $this->registry);

        $result = $builder->build([
            'interface1_name' => 'Bad Interface',
            'interface1_deliverymethod' => 'Carrier Pigeon',
        ], 2);

        $this->assertNotEmpty(array_filter(
            $result->getErrors(),
            static fn($e) => str_contains($e, "'deliveryMethod' value 'Carrier Pigeon' is not one of")
        ));
    }

    public function testInterfaceHasNoRequiredFields(): void {
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceSchema::class, '|', $this->registry);

        $result = $builder->build(['interface1_notes' => 'Just notes, no name'], 2);

        $this->assertSame([], $result->getErrors());
        $this->assertSame(['notes' => 'Just notes, no name'], $result->getRecord());
    }

    public function testInterfaceCredentialBuildsFromUsernameAndPassword(): void {
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceCredentialSchema::class, '|', $this->registry);

        $result = $builder->build([
            'interface1_username' => 'admin_user',
            'interface1_password' => 'sekret',
        ], 2);

        $this->assertSame([], $result->getErrors());
        $this->assertSame(['username' => 'admin_user', 'password' => 'sekret'], $result->getRecord());
    }

    public function testInterfaceCredentialMissingPasswordIsReported(): void {
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceCredentialSchema::class, '|', $this->registry);

        $result = $builder->build(['interface1_username' => 'admin_user'], 2);

        $this->assertContains("Row 2: missing required field 'password'", $result->getErrors());
    }

    public function testInterfaceWithNoCredentialColumnsBuildsEmptyCredentialRecord(): void {
        // Required-field errors still fire on an all-empty record (same as
        // any other schema) — callers building 0+ records per row (like
        // buildInterfacesAndCredentials() in bin/build-organizations) are
        // expected to check getRecord() is non-empty *before* checking
        // getErrors(), exactly like the contacts/interfaces loop already
        // does, so an interface with no credentials mapped doesn't produce
        // a spurious "missing username/password" error.
        $subMapper = $this->mapper->forInstance('interfaces', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, InterfaceCredentialSchema::class, '|', $this->registry);

        $result = $builder->build(['interface1_name' => 'No Credentials Here'], 2);

        $this->assertSame([], $result->getRecord());
    }

    public function testBuildsTwoNotesFromOneRow(): void {
        $notes = $this->buildAll('notes', NoteSchema::class, [
            'note1_type' => 'General',
            'note1_title' => 'First contact',
            'note1_content' => 'Called about renewal',
            'note2_type' => 'Follow-up',
            'note2_title' => 'Check back next quarter',
        ]);

        $this->assertCount(2, $notes);
        $this->assertSame('First contact', $notes[0]['title']);
        $this->assertSame('Called about renewal', $notes[0]['content']);
        $this->assertSame('Check back next quarter', $notes[1]['title']);
        $this->assertArrayNotHasKey('content', $notes[1]);
    }

    public function testNoteMissingRequiredTitleIsReported(): void {
        $subMapper = $this->mapper->forInstance('notes', 1);
        $builder = new RecordBuilder($subMapper, $this->caster, $this->folioUtils, NoteSchema::class, '|', $this->registry);

        $result = $builder->build(['note1_type' => 'General'], 2);

        $this->assertContains("Row 2: missing required field 'title'", $result->getErrors());
    }

    public function testRowWithNoNoteDataBuildsNoNotes(): void {
        $notes = $this->buildAll('notes', NoteSchema::class, [
            'name' => 'No Notes Co',
        ]);

        $this->assertSame([], $notes);
    }

    public function testNoteTypeNameResolvesThroughSharedRegistry(): void {
        $notes = $this->buildAll('notes', NoteSchema::class, [
            'note1_type' => 'General',
            'note1_title' => 'First contact',
        ]);

        $this->assertSame($this->registry->resolve('noteType', 'General'), $notes[0]['typeId']);
    }

    public function testNoteRecordHasNoDomainOrLinksAtThisLayer(): void {
        // domain and links are assembled by buildNotes() in
        // bin/build-organizations, not by RecordBuilder/NoteSchema — see
        // NoteSchema's docblock for why domain specifically can't be a
        // hardcoded mapping-file value (it would make an unused instance
        // look non-empty and defeat the empty-record skip below).
        $notes = $this->buildAll('notes', NoteSchema::class, [
            'note1_type' => 'General',
            'note1_title' => 'First contact',
        ]);

        $this->assertArrayNotHasKey('domain', $notes[0]);
        $this->assertArrayNotHasKey('links', $notes[0]);
    }

    public function testUnusedNoteInstanceBuildsEmptyNotAFalseRequiredFieldError(): void {
        // Regression test: notes[2] has nothing mapped here, so it must
        // resolve to a genuinely empty record (and therefore be skipped
        // by buildAll()/buildNotes() alike) rather than spuriously
        // "having" just a hardcoded domain and then failing required-field
        // validation for the typeId/title that were never meant to apply.
        $notes = $this->buildAll('notes', NoteSchema::class, [
            'note1_type' => 'General',
            'note1_title' => 'First contact',
        ]);

        $this->assertCount(1, $notes);
    }
}
