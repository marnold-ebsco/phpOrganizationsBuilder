<?php declare(strict_types=1);

namespace Organizations;

use Organizations\Casting\ValueCaster;
use Organizations\Mapping\FieldMapper;
use phpFolioClient\FolioUtils;

/**
 * Builds one FOLIO record — organization, contact, or interface, per
 * whichever schema class is given (see {@see \Organizations\Schema\OrganizationSchema},
 * {@see \Organizations\Schema\ContactSchema}, {@see \Organizations\Schema\InterfaceSchema})
 * — from one input row. Resolves each field's raw value via a
 * {@see FieldMapper}, casts/validates it via a {@see ValueCaster} and
 * {@see FolioUtils}, and collects any validation failures rather than
 * throwing.
 *
 * A schema class is any class exposing these `public const` arrays:
 * `SCALAR_FIELDS` (field => cast type: `string`/`bool`/`int`/`number`),
 * `LIST_FIELDS` (field => item type: `string`/`uuid`/`ref:<namespace>`),
 * `LIST_FIELD_ENUMS` (field => allowed values, checked against each item
 * of a `string`-typed list field), `NESTED_GROUPS` (schema array property
 * => group spec, as documented on
 * {@see \Organizations\Schema\OrganizationSchema::NESTED_GROUPS}),
 * `REQUIRED_FIELDS` (top-level field names), `TOP_LEVEL_ENUMS` (field =>
 * allowed values), and `TOP_LEVEL_PATTERNS` (field => regex).
 */
final class RecordBuilder {
    /**
     * @param $mapper        Resolves legacy column data to record fields.
     * @param $caster        Casts/splits raw cell values.
     * @param $folioUtils    Used for UUID validation.
     * @param $schemaClass   Fully-qualified name of a schema class (see
     *                       class docblock for the shape it must expose).
     * @param $listDelimiter Delimiter used *within* a cell for
     *                       multi-value fields (default `|`) — except
     *                       category name lists, which always use
     *                       {@see CATEGORY_DELIMITER} regardless of this
     *                       setting (see its own docblock for why).
     * @param $registry      Shared reference-data resolver for `category_ref_list`/
     *                       `ref:<namespace>` fields; required if the
     *                       schema uses any, otherwise unused.
     */
    public function __construct(
        private readonly FieldMapper $mapper,
        private readonly ValueCaster $caster,
        private readonly FolioUtils $folioUtils,
        private readonly string $schemaClass,
        private readonly string $listDelimiter = '|',
        private readonly ?ReferenceRegistry $registry = null,
    ) {
    }

    /**
     * Delimiter for a cell listing more than one category name — fixed
     * at `;`, independent of the general `$listDelimiter` every other
     * multi-value field uses. Categories get their own delimiter because
     * they're the one list field a spreadsheet author is likely to type
     * by hand often enough that a semicolon (visually distinct from
     * punctuation that might appear in a category name itself) is worth
     * having, rather than sharing whatever delimiter the rest of the row
     * happens to use.
     */
    private const CATEGORY_DELIMITER = ';';

    /**
     * Build and validate one record from one row.
     *
     * @param $row    The row, keyed by lowercased column header.
     * @param $rowNum Row number, used only to phrase error messages.
     */
    public function build(array $row, int $rowNum): RecordBuildResult {
        $errors = [];
        $record = [];
        $schemaClass = $this->schemaClass;

        foreach ($schemaClass::SCALAR_FIELDS as $field => $type) {
            $raw = $this->mapper->resolve($field, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            $value = $this->caster->cast($raw, $type, $field, $errors, $rowNum);
            if ($value !== null) {
                $record[$field] = $value;
            }
        }

        foreach ($schemaClass::LIST_FIELDS as $field => $itemType) {
            $raw = $this->mapper->resolve($field, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            $namespace = str_starts_with($itemType, 'ref:') ? substr($itemType, strlen('ref:')) : null;
            $delimiter = $namespace === 'category' ? self::CATEGORY_DELIMITER : $this->listDelimiter;
            $items = $this->caster->splitList($raw, $delimiter);
            if ($itemType === 'uuid') {
                foreach ($items as $item) {
                    if (!$this->folioUtils->isValidUuid($item)) {
                        $errors[] = "Row $rowNum: '$field' contains invalid UUID '$item'";
                    }
                }
            } elseif ($namespace !== null) {
                $items = array_map(fn($item) => $this->registry->resolve($namespace, $item, $rowNum), $items);
            } elseif (isset($schemaClass::LIST_FIELD_ENUMS[$field])) {
                $allowedValues = $schemaClass::LIST_FIELD_ENUMS[$field];
                foreach ($items as $item) {
                    if (!in_array($item, $allowedValues, true)) {
                        $errors[] = "Row $rowNum: '$field' contains '$item', which is not one of: " . implode(', ', $allowedValues);
                    }
                }
            }
            if ($items) {
                $record[$field] = $items;
            }
        }

        foreach ($schemaClass::NESTED_GROUPS as $schemaKey => $spec) {
            $instances = [];
            foreach ($this->mapper->indicesFor($schemaKey) as $index) {
                $sub = $this->buildNestedGroupInstance($schemaKey, $index, $spec, $row, $errors, $rowNum);
                if ($sub !== null) {
                    $instances[] = $sub;
                }
            }
            if ($instances !== []) {
                $record[$schemaKey] = $this->applyDefaultPrimary($instances, $spec);
            }
        }

        $this->validateTopLevel($record, $errors, $rowNum);

        return new RecordBuildResult($record, $errors);
    }

    /**
     * Build one instance of a nested group (e.g. the 2nd address) from
     * its mapped sub-fields.
     *
     * @param $schemaKey Schema array property name (e.g. `addresses`).
     * @param $index     1-based instance number within this group,
     *                   matching a {@see FieldMapper::indicesFor()} result.
     * @param $spec      This group's `NESTED_GROUPS` entry.
     * @param $row       The row, keyed by lowercased column header.
     * @param $errors    Error list to append to.
     * @param $rowNum    Row number, used only to phrase error messages.
     * @return The built sub-object, or null if every field in this
     *         instance was empty (i.e. it doesn't apply to this row).
     */
    private function buildNestedGroupInstance(string $schemaKey, int $index, array $spec, array $row, array &$errors, int $rowNum): ?array {
        $groupLabel = "{$schemaKey}[{$index}]";
        $sub = [];
        foreach ($spec['fields'] as $subField => $subType) {
            $fullFieldName = "{$groupLabel}.{$subField}";
            $raw = $this->mapper->resolve($fullFieldName, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }

            if ($subType === 'uuid_list' || $subType === 'category_ref_list') {
                $delimiter = $subType === 'category_ref_list' ? self::CATEGORY_DELIMITER : $this->listDelimiter;
                $items = $this->caster->splitList($raw, $delimiter);
                if ($subType === 'uuid_list') {
                    foreach ($items as $item) {
                        if (!$this->folioUtils->isValidUuid($item)) {
                            $errors[] = "Row $rowNum: '$fullFieldName' contains invalid UUID '$item'";
                        }
                    }
                } else {
                    $items = array_map(fn($item) => $this->registry->resolve('category', $item, $rowNum), $items);
                }
                if ($items) {
                    $sub[$subField] = $items;
                }
                continue;
            }

            $value = $this->caster->cast($raw, $subType, $fullFieldName, $errors, $rowNum);
            if ($value === null) {
                continue;
            }
            if (isset($spec['enums'][$subField]) && !in_array($value, $spec['enums'][$subField], true)) {
                $errors[] = "Row $rowNum: '$fullFieldName' value '$value' is not one of: " . implode(', ', $spec['enums'][$subField]);
            }
            if (isset($spec['pattern'][$subField]) && !preg_match($spec['pattern'][$subField], (string) $value)) {
                $errors[] = "Row $rowNum: '$fullFieldName' value '$value' does not look like a valid URL";
            }
            $sub[$subField] = $value;
        }

        if (empty($sub)) {
            return null;
        }

        foreach ($spec['required'] as $requiredField) {
            if (!isset($sub[$requiredField]) || $sub[$requiredField] === '') {
                $errors[] = "Row $rowNum: '$groupLabel' group is missing required field '$groupLabel.$requiredField'";
            }
        }

        return $sub;
    }

    /**
     * If a group supports `isPrimary` and no instance is explicitly
     * `true`, pick one to default to `true` — preserving the
     * pre-multi-instance behavior where a single instance was always
     * primary, now generalized to multiple instances:
     *
     *   - If some instance already has `isPrimary: true`, nothing is
     *     touched — an explicit "yes" anywhere is always respected.
     *   - Otherwise, the first instance that wasn't explicitly marked
     *     `false` is defaulted to `true` (i.e. the first one, unless
     *     it's explicitly "no", in which case the next one that isn't,
     *     and so on).
     *   - If literally every instance was explicitly marked `false`,
     *     that's left alone too — an all-explicit "no" is respected
     *     rather than overridden.
     *
     * @param $instances Non-empty list of built sub-objects for one group.
     * @param $spec      This group's `NESTED_GROUPS` entry.
     */
    private function applyDefaultPrimary(array $instances, array $spec): array {
        if (empty($spec['primaryFlag'])) {
            return $instances;
        }
        foreach ($instances as $sub) {
            if (($sub['isPrimary'] ?? null) === true) {
                return $instances;
            }
        }
        foreach ($instances as $index => $sub) {
            if (!array_key_exists('isPrimary', $sub)) {
                $instances[$index]['isPrimary'] = true;
                return $instances;
            }
        }
        return $instances;
    }

    /**
     * Validate the fully-assembled record's top-level required fields
     * and cross-field constraints, appending to `$errors`. `id`, if
     * present, is always checked as a UUID regardless of schema — every
     * FOLIO record shares that constraint.
     */
    private function validateTopLevel(array $record, array &$errors, int $rowNum): void {
        $schemaClass = $this->schemaClass;

        foreach ($schemaClass::REQUIRED_FIELDS as $requiredField) {
            if (empty($record[$requiredField])) {
                $errors[] = "Row $rowNum: missing required field '$requiredField'";
            }
        }
        foreach ($schemaClass::TOP_LEVEL_PATTERNS as $field => $pattern) {
            if (isset($record[$field]) && !preg_match($pattern, (string) $record[$field])) {
                $errors[] = "Row $rowNum: '$field' value '{$record[$field]}' does not match the expected format";
            }
        }
        foreach ($schemaClass::TOP_LEVEL_ENUMS as $field => $allowedValues) {
            if (isset($record[$field]) && !in_array($record[$field], $allowedValues, true)) {
                $errors[] = "Row $rowNum: '$field' value '{$record[$field]}' is not one of: " . implode(', ', $allowedValues);
            }
        }
        if (isset($record['id']) && !$this->folioUtils->isValidUuid((string) $record['id'])) {
            $errors[] = "Row $rowNum: 'id' value '{$record['id']}' is not a valid UUID";
        }
    }
}
