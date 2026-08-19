<?php declare(strict_types=1);

namespace Organizations\Tests;

use Organizations\ReferenceRegistry;
use phpFolioClient\FolioUtils;
use PHPUnit\Framework\TestCase;

final class ReferenceRegistryTest extends TestCase {
    private ReferenceRegistry $registry;

    protected function setUp(): void {
        $this->registry = new ReferenceRegistry();
    }

    public function testResolveGeneratesAValidUuid(): void {
        $uuid = $this->registry->resolve('category', 'Billing');
        $this->assertTrue((new FolioUtils())->isValidUuid($uuid));
    }

    public function testSameNameResolvesToTheSameUuid(): void {
        $first = $this->registry->resolve('category', 'Billing');
        $second = $this->registry->resolve('category', 'Billing');
        $this->assertSame($first, $second);
    }

    public function testResolveIsCaseInsensitiveAndTrimsWhitespace(): void {
        $a = $this->registry->resolve('category', 'Billing');
        $b = $this->registry->resolve('category', '  billing  ');
        $this->assertSame($a, $b);
    }

    public function testDifferentNamesGetDifferentUuids(): void {
        $billing = $this->registry->resolve('category', 'Billing');
        $sales = $this->registry->resolve('category', 'Sales');
        $this->assertNotSame($billing, $sales);
    }

    public function testNamespacesAreIndependent(): void {
        $categoryUuid = $this->registry->resolve('category', 'Vendor');
        $orgTypeUuid = $this->registry->resolve('organizationType', 'Vendor');
        $this->assertNotSame($categoryUuid, $orgTypeUuid);
    }

    public function testGetRecordsReturnsAccumulatedEntriesInFirstSeenOrder(): void {
        $billingId = $this->registry->resolve('category', 'Billing');
        $salesId = $this->registry->resolve('category', 'Sales');

        $records = $this->registry->getRecords('category', 'value');

        $this->assertSame([
            ['id' => $billingId, 'value' => 'Billing'],
            ['id' => $salesId, 'value' => 'Sales'],
        ], $records);
    }

    public function testGetRecordsAppliesExtraDefaults(): void {
        $id = $this->registry->resolve('organizationType', 'Vendor');

        $records = $this->registry->getRecords('organizationType', 'name', ['status' => 'Active']);

        $this->assertSame([['id' => $id, 'name' => 'Vendor', 'status' => 'Active']], $records);
    }

    public function testGetRecordsReturnsEmptyArrayForUnusedNamespace(): void {
        $this->assertSame([], $this->registry->getRecords('category', 'value'));
    }

    public function testSeededNameResolvesToTheSeededUuidNotAFreshOne(): void {
        $this->registry->seed('category', 'Billing', 'aaaaaaaa-1111-4111-8111-111111111111');

        $this->assertSame('aaaaaaaa-1111-4111-8111-111111111111', $this->registry->resolve('category', 'Billing'));
    }

    public function testSeededEntriesAreExcludedFromGetRecords(): void {
        $this->registry->seed('category', 'Billing', 'aaaaaaaa-1111-4111-8111-111111111111');
        $this->registry->resolve('category', 'Sales'); // not seeded — needs creating

        $records = $this->registry->getRecords('category', 'value');

        $this->assertCount(1, $records);
        $this->assertSame('Sales', $records[0]['value']);
    }

    public function testSeedMatchingIsCaseInsensitiveAndTrimsWhitespace(): void {
        $this->registry->seed('category', '  Billing  ', 'aaaaaaaa-1111-4111-8111-111111111111');

        $this->assertSame('aaaaaaaa-1111-4111-8111-111111111111', $this->registry->resolve('category', 'billing'));
    }

    public function testGetSeededNamesReturnsNamesInSeedOrder(): void {
        $this->registry->seed('category', 'Billing', 'aaaaaaaa-1111-4111-8111-111111111111');
        $this->registry->seed('category', 'Sales', 'bbbbbbbb-2222-4222-8222-222222222222');

        $this->assertSame(['Billing', 'Sales'], $this->registry->getSeededNames('category'));
    }

    public function testGetSeededNamesExcludesNamesResolvedWithoutSeeding(): void {
        $this->registry->seed('category', 'Billing', 'aaaaaaaa-1111-4111-8111-111111111111');
        $this->registry->resolve('category', 'Sales'); // newly generated, not seeded

        $this->assertSame(['Billing'], $this->registry->getSeededNames('category'));
    }

    public function testGetSeededNamesReturnsEmptyArrayForUnusedNamespace(): void {
        $this->assertSame([], $this->registry->getSeededNames('category'));
    }

    public function testGetReferencingRowsTracksEveryRowThatResolvedAName(): void {
        $this->registry->resolve('category', 'Billing', 2);
        $this->registry->resolve('category', 'Billing', 5);

        $this->assertSame([2, 5], $this->registry->getReferencingRows('category', 'Billing'));
    }

    public function testGetReferencingRowsIsCaseInsensitiveAndTrimsWhitespace(): void {
        $this->registry->resolve('category', 'Billing', 2);

        $this->assertSame([2], $this->registry->getReferencingRows('category', '  billing  '));
    }

    public function testGetReferencingRowsIgnoresResolutionsWithNoRowNumber(): void {
        $this->registry->resolve('category', 'Billing');

        $this->assertSame([], $this->registry->getReferencingRows('category', 'Billing'));
    }

    public function testGetReferencingRowsReturnsEmptyArrayForUnknownName(): void {
        $this->assertSame([], $this->registry->getReferencingRows('category', 'Nonexistent'));
    }

    public function testGeneratedUuidsAreVersion5Variant1(): void {
        $uuid = $this->registry->resolve('category', 'Billing');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testSameNameResolvesToTheSameUuidAcrossSeparateRegistries(): void {
        // Not just within-run caching (testSameNameResolvesToTheSameUuid
        // already covers that) — a completely separate registry instance,
        // as a separate run of bin/build-organizations would construct,
        // must reproduce the exact same id for the same name.
        $first = (new ReferenceRegistry())->resolve('category', 'Billing');
        $second = (new ReferenceRegistry())->resolve('category', 'Billing');

        $this->assertSame($first, $second);
    }

    public function testDifferentTenantsGetDifferentUuidsForTheSameName(): void {
        $offline = (new ReferenceRegistry('offline'))->resolve('category', 'Billing');
        $tenantA = (new ReferenceRegistry('diku'))->resolve('category', 'Billing');

        $this->assertNotSame($offline, $tenantA);
    }

    public function testGenerateUuidV5MatchesTheDocumentedFolioUuidExample(): void {
        // From https://github.com/FOLIO-FSE/folio_uuid's own README:
        // namespace 8405ae4d-b315-42e1-918a-d1919900cf3f + name
        // "https://okapi-bugfest-juniper.folio.ebsco.com:items:i3696836"
        // is documented to produce this exact UUID — proof this
        // implementation matches the real convention byte-for-byte, not
        // just "looks like a v5 UUID".
        $uuid = ReferenceRegistry::generateUuidV5(
            ReferenceRegistry::FOLIO_NAMESPACE,
            'https://okapi-bugfest-juniper.folio.ebsco.com:items:i3696836'
        );

        $this->assertSame('9647225d-d8e9-530d-b8cc-52a53be14e26', $uuid);
    }
}
