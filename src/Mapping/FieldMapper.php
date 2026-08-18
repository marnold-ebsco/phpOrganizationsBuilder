<?php declare(strict_types=1);

namespace Organizations\Mapping;

use RuntimeException;

/**
 * Resolves the value of a FOLIO field for one input row, per the rules
 * encoded in a legacy-column-to-FOLIO-field mapping file:
 *
 *   {
 *       "data": [
 *           {
 *               "folio_field": "name",
 *               "legacy_field": "Vendor Name",
 *               "value": "",
 *               "description": "The name of this organization",
 *               "fallback_legacy_field": "Company Name"
 *           }
 *       ]
 *   }
 *
 * For a given `folio_field`:
 *   1. A non-empty "value" is used verbatim (hard-coded), regardless of
 *      the row's data.
 *   2. Otherwise, the row's `legacy_field` column is used, if present
 *      and non-empty.
 *   3. Otherwise, the row's `fallback_legacy_field` column is used, if
 *      present and non-empty.
 *   4. Otherwise the field resolves to null (not present in this row).
 * "legacy_field"/"fallback_legacy_field" of "" or "Not mapped" are
 * treated as absent. Column name matching is case-insensitive.
 *
 * A nested group field's `folio_field` may name a specific instance with
 * `[N]` (1-based) before the dot, e.g. `addresses[2].city`, to support
 * more than one address/phone/email/url/alias per organization. Omitting
 * the bracket (e.g. `addresses.city`) is shorthand for `addresses[1].city`
 * — existing mapping files with no brackets keep working unchanged, and
 * only build one instance per group, exactly as before.
 */
final class FieldMapper {
    /** @var array<string, array<string, mixed>> Indexed by normalized, lowercased folio_field. */
    private array $index;

    /**
     * @param $index Mapping entries indexed by lowercased `folio_field`
     *               (bracket-less nested keys are normalized to `[1]`
     *               automatically). Prefer {@see fromFile()} unless
     *               constructing an index directly (e.g. in tests).
     */
    public function __construct(array $index) {
        $this->index = [];
        foreach ($index as $key => $entry) {
            $this->index[self::normalizeKey((string) $key)] = $entry;
        }
    }

    /**
     * Normalize a lowercased `folio_field` key: a bracket-less nested
     * group key (`addresses.city`) is rewritten to explicit instance 1
     * (`addresses[1].city`); anything else (top-level fields, or keys
     * that already have a `[N]`) passes through unchanged.
     */
    private static function normalizeKey(string $key): string {
        if (preg_match('/^([^.\[]+)\.(.+)$/', $key, $m) === 1) {
            return "{$m[1]}[1].{$m[2]}";
        }
        return $key;
    }

    /**
     * Load a mapping file and build a {@see FieldMapper} from it.
     *
     * @param $path Path to the mapping JSON file.
     * @throws RuntimeException If the file can't be read, or isn't a
     *                          JSON object with a top-level `data` array.
     */
    public static function fromFile(string $path): self {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read mapping file '$path'");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RuntimeException("Mapping file '$path' must be a JSON object with a top-level 'data' array");
        }

        $index = [];
        foreach ($decoded['data'] as $entry) {
            if (!is_array($entry) || empty($entry['folio_field'])) {
                continue;
            }
            $index[strtolower((string) $entry['folio_field'])] = $entry;
        }
        return new self($index);
    }

    /**
     * Resolve the raw (pre-cast) value of a FOLIO field for one row.
     *
     * @param $folioField Dot-notation for nested group fields (e.g.
     *                    `addresses.city` or `addresses[2].city`), or a
     *                    bare top-level field name otherwise. Matched
     *                    case-insensitively.
     * @param $row        The row, keyed by lowercased column header.
     * @return The resolved raw string value, or null if nothing in the
     *         mapping applies to this row.
     */
    public function resolve(string $folioField, array $row): ?string {
        $entry = $this->index[self::normalizeKey(strtolower($folioField))] ?? null;
        if ($entry === null) {
            return null;
        }

        if (isset($entry['value']) && (string) $entry['value'] !== '') {
            return (string) $entry['value'];
        }

        foreach (['legacy_field', 'fallback_legacy_field'] as $key) {
            $columnName = trim((string) ($entry[$key] ?? ''));
            if ($columnName === '' || strcasecmp($columnName, 'Not mapped') === 0) {
                continue;
            }
            $raw = $row[strtolower($columnName)] ?? null;
            if ($raw !== null && trim((string) $raw) !== '') {
                return (string) $raw;
            }
        }

        return null;
    }

    /**
     * Find which instance numbers a nested group has mapping entries
     * for, e.g. `[1, 2]` if the mapping defines `addresses.*`/
     * `addresses[1].*` and `addresses[2].*` fields but nothing for
     * `addresses[3]`. Determines how many address/phone/email/url/alias
     * instances {@see \Organizations\RecordBuilder} will attempt
     * to build for that group — an instance whose fields all resolve
     * empty for a given row is simply omitted, so it's fine for the
     * mapping to define more instances than any single row uses.
     *
     * @param $schemaKey Schema array property name (e.g. `addresses`).
     * @return Sorted, unique instance numbers (1-based); empty if the
     *         mapping has no entries at all for this group.
     */
    public function indicesFor(string $schemaKey): array {
        $prefix = strtolower($schemaKey);
        $indices = [];
        foreach (array_keys($this->index) as $key) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\[(\d+)\]\./', $key, $m) === 1) {
                $indices[(int) $m[1]] = true;
            }
        }
        $result = array_keys($indices);
        sort($result);
        return $result;
    }

    /**
     * Carve out a standalone sub-mapper for one instance of a top-level
     * multi-instance group (e.g. the 2nd contact person on an
     * organization's row), by stripping the `{schemaKey}[{index}].`
     * prefix from every matching entry. The result can be handed to a
     * {@see \Organizations\RecordBuilder} configured with that record's
     * own schema (e.g. {@see \Organizations\Schema\ContactSchema}) using
     * that schema's own bare field names (`firstName`, `emails.value`,
     * ...) — exactly as if it were the top-level mapping for that record.
     *
     * This is how one physical input row can carry several independent
     * child records (contacts, interfaces) alongside the organization
     * itself: `contacts[1].firstName`/`contacts[2].firstName` in the
     * mapping file become plain `firstName` once viewed through
     * `forInstance('contacts', 1)` / `forInstance('contacts', 2)`.
     *
     * @param $schemaKey Top-level group name (e.g. `contacts`, `interfaces`).
     * @param $index     1-based instance number, matching an
     *                   {@see indicesFor()} result.
     */
    public function forInstance(string $schemaKey, int $index): self {
        $prefix = strtolower($schemaKey) . "[{$index}].";
        $prefixLen = strlen($prefix);
        $subIndex = [];
        foreach ($this->index as $key => $entry) {
            if (str_starts_with($key, $prefix)) {
                $subIndex[substr($key, $prefixLen)] = $entry;
            }
        }
        return new self($subIndex);
    }
}
