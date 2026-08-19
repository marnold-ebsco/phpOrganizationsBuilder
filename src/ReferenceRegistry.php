<?php declare(strict_types=1);

namespace Organizations;

/**
 * Deduplicates named reference-data values (e.g. category names,
 * organization-type names) into deterministic UUIDs, shared across a
 * whole build run so the same name always resolves to the same UUID no
 * matter which record (organization, contact, ...) mentions it first —
 * and, since the id is deterministic (see {@see FOLIO_NAMESPACE}), the
 * *same* UUID again on a completely separate run, too.
 *
 * Reference data in FOLIO (categories, organization types) are their own
 * resources, addressed by UUID from elsewhere. When the caller already
 * knows a name's real UUID from the target FOLIO tenant (see
 * {@see seed()} — typically because bin/build-organizations looked it up
 * there first), that real UUID is reused and nothing new needs creating.
 * Only for a name with no existing match does this registry generate a
 * UUID of its own, and {@see getRecords()} dumps *just those* records
 * afterward — the ones that still need to be created.
 */
final class ReferenceRegistry {
    /**
     * FOLIO's own well-known namespace for deterministic (UUID v5) ids —
     * see {@see resolve()}. Matches the convention FOLIO's own migration
     * tooling uses (the `folio_uuid` Python library:
     * https://github.com/FOLIO-FSE/folio_uuid), so an id computed here is
     * byte-identical to one any other tool following that same
     * convention would compute for the same name.
     */
    public const FOLIO_NAMESPACE = '8405ae4d-b315-42e1-918a-d1919900cf3f';

    /** @var array<string, array<string, string>> namespace => (lowercased name => uuid) */
    private array $uuidsByName = [];

    /** @var array<string, array<string, string>> namespace => (uuid => original-case name), insertion order preserved; only newly-generated (not seeded) entries. */
    private array $namesByUuid = [];

    /** @var array<string, list<string>> namespace => names registered via {@see seed()}, insertion order preserved — reporting only, not consulted by resolve()/getRecords(). */
    private array $seededNames = [];

    /** @var array<string, array<string, list<int>>> namespace => (lowercased name => every row number that ever resolved it) — reporting only, e.g. for orphan-detection when a name's only referencing rows all belong to rejected organizations. */
    private array $referencingRows = [];

    /**
     * @param $tenant Tenant id (from `--folio-config`, when given) mixed
     *                into every deterministic id this registry generates,
     *                so the same name gets a different id in a different
     *                target tenant — matching the real `folio_uuid`
     *                convention exactly. Defaults to the fixed placeholder
     *                `'offline'` when there's no real tenant to use
     *                (running without `--folio-config`, the default) —
     *                still fully deterministic run-to-run, just not
     *                tenant-scoped.
     */
    public function __construct(
        private readonly string $tenant = 'offline',
    ) {
    }

    /**
     * Register a name's real, already-existing UUID (e.g. fetched from
     * the target FOLIO tenant) so {@see resolve()} reuses it instead of
     * generating a new one. Seeded entries do not appear in
     * {@see getRecords()} — there is nothing to create for them — but
     * are tracked separately in {@see getSeededNames()} so a caller can
     * log that this reference data already existed.
     *
     * @param $namespace Logical grouping (e.g. `category`, `organizationType`).
     * @param $name      The known name; matching is case-insensitive and
     *                   ignores surrounding whitespace, same as {@see resolve()}.
     * @param $uuid      That name's real UUID.
     */
    public function seed(string $namespace, string $name, string $uuid): void {
        $this->uuidsByName[$namespace][strtolower(trim($name))] = $uuid;
        $this->seededNames[$namespace][] = trim($name);
    }

    /**
     * Names registered via {@see seed()} for a namespace, in the order
     * they were seeded — i.e. reference data confirmed to already exist
     * in whatever source seeded the registry (typically a live FOLIO
     * tenant, via bin/build-organizations's `--folio-config`).
     *
     * @param $namespace Logical grouping (e.g. `category`, `organizationType`).
     * @return List of names; empty if nothing was seeded for this namespace.
     */
    public function getSeededNames(string $namespace): array {
        return $this->seededNames[$namespace] ?? [];
    }

    /**
     * Resolve a name to its UUID within a namespace: reuses a
     * {@see seed()}ed UUID if one matches, otherwise computes a
     * deterministic one (`uuid5(FOLIO_NAMESPACE, "{tenant}:{namespace}:{key}")`
     * — see {@see FOLIO_NAMESPACE}) the first time this (namespace, name)
     * pair is seen. The hash uses the same lowercased/trimmed key
     * matching is already done on, so two names this method already
     * treats as equivalent (`Billing`/`billing`) keep resolving to the
     * same id on a separate run too, not just within this one.
     *
     * @param $namespace Logical grouping (e.g. `category`, `organizationType`).
     * @param $name      The raw name to resolve; matching is
     *                   case-insensitive and ignores surrounding whitespace.
     * @param $rowNum    Input row this reference came from, if the caller
     *                   has one — tracked purely for {@see getReferencingRows()}
     *                   orphan-detection reporting; resolution itself
     *                   doesn't need it.
     * @return The resolved UUID.
     */
    public function resolve(string $namespace, string $name, ?int $rowNum = null): string {
        $name = trim($name);
        $key = strtolower($name);

        if (!isset($this->uuidsByName[$namespace][$key])) {
            $combined = implode(':', [$this->tenant, $namespace, $key]);
            $uuid = self::generateUuidV5(self::FOLIO_NAMESPACE, $combined);
            $this->uuidsByName[$namespace][$key] = $uuid;
            $this->namesByUuid[$namespace][$uuid] = $name;
        }

        if ($rowNum !== null) {
            $this->referencingRows[$namespace][$key][] = $rowNum;
        }

        return $this->uuidsByName[$namespace][$key];
    }

    /**
     * Every input row number that ever resolved a given name, in a given
     * namespace — e.g. useful to tell whether a category/organization
     * type is only ever referenced by rejected organizations' rows (see
     * bin/build-organizations's orphan-detection logging).
     *
     * @param $namespace Logical grouping (e.g. `category`, `organizationType`).
     * @param $name      The name to look up; matching is case-insensitive
     *                   and ignores surrounding whitespace, same as {@see resolve()}.
     * @return List of row numbers (with duplicates, if the same row
     *         referenced the name more than once); empty if none were
     *         tracked (e.g. a {@see seed()}ed name, or one resolved
     *         without a `$rowNum`).
     */
    public function getReferencingRows(string $namespace, string $name): array {
        return $this->referencingRows[$namespace][strtolower(trim($name))] ?? [];
    }

    /**
     * Dump every *newly-generated* record accumulated in a namespace (in
     * first-seen order) — i.e. excludes anything registered via
     * {@see seed()}, since those already exist and don't need creating.
     *
     * @param $namespace    Logical grouping to dump (e.g. `category`).
     * @param $nameField    Schema property name the resolved name should be stored under (e.g. `value`, `name`).
     * @param $extraDefaults Additional constant fields to merge into every record (e.g. `['status' => 'Active']`).
     * @return List of `['id' => ..., $nameField => ..., ...$extraDefaults]` records.
     */
    public function getRecords(string $namespace, string $nameField, array $extraDefaults = []): array {
        $records = [];
        foreach ($this->namesByUuid[$namespace] ?? [] as $uuid => $name) {
            $records[] = array_merge(['id' => $uuid, $nameField => $name], $extraDefaults);
        }
        return $records;
    }

    /**
     * Generate a random UUID v4 (version/variant nibbles set per RFC 4122),
     * matching the format {@see \phpFolioClient\FolioUtils::isValidUuid()} accepts.
     */
    public static function generateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Generate a deterministic UUID v5 (version/variant nibbles set per
     * RFC 4122): the same `$namespace`/`$name` pair always produces the
     * same UUID. Computed exactly per RFC 4122 §4.3 — SHA-1 of the
     * namespace's 16 raw bytes followed by `$name`, truncated to 16
     * bytes with version/variant bits overwritten — the same algorithm
     * Python's `uuid.uuid5()` (and the FOLIO ecosystem's own `folio_uuid`
     * library) use, so this produces identical output for identical
     * input regardless of which implementation computed it.
     *
     * @param $namespace A UUID string — e.g. {@see FOLIO_NAMESPACE}.
     * @param $name      The value to hash within that namespace.
     */
    public static function generateUuidV5(string $namespace, string $name): string {
        $namespaceBytes = hex2bin(str_replace('-', '', $namespace));
        $hash = sha1((string) $namespaceBytes . $name, true);
        $data = substr($hash, 0, 16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x50);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
