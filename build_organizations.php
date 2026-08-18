<?php declare(strict_types=1);

/**
 * build_organizations.php
 *
 * Reads a delimited file (CSV/TSV/pipe-delimited/etc.) of organization data
 * and writes one JSON object per row conforming to the FOLIO
 * mod-organizations-storage `organization` schema:
 * https://s3.amazonaws.com/foliodocs/api/mod-organizations-storage/r/organization.html#organizations_storage_organizations_post
 *
 * This script only builds and validates the JSON objects — it does not
 * talk to a FOLIO instance. Feed the resulting file to
 * phpFolioClient\FolioClient::post('/organizations-storage/organizations', $org)
 * yourself (one call per object) if you want to actually create the records.
 *
 * ---------------------------------------------------------------------------
 * Usage
 * ---------------------------------------------------------------------------
 *
 *   php build_organizations.php --input=orgs.csv [options]
 *
 * Options:
 *   --input=PATH          Delimited input file (required).
 *   --output=PATH         Output file; defaults to stdout.
 *   --mapping=PATH        Field-mapping JSON file (default:
 *                         organization_field_mapping.json next to this
 *                         script — see below).
 *   --format=json|ndjson  Output format (default: ndjson — one JSON object
 *                         per line, the form most loading tools expect).
 *                         "json" writes a single JSON array of organization
 *                         objects instead.
 *   --delimiter=CHAR      Field delimiter (default: tab — the normal
 *                         delimiter for input files). Accepts the literal
 *                         character, or one of the convenience names "tab",
 *                         "pipe", "semicolon", "comma".
 *   --enclosure=CHAR      Field quote/enclosure character (default: ").
 *   --list-delimiter=STR  Delimiter used *within* a single cell for
 *                         multi-value fields such as organizationTypes
 *                         (default: |). Same convenience names as above
 *                         are accepted.
 *   --error-log=PATH      Error/validation log file (default: a fresh,
 *                         timestamped file under logs/ next to this
 *                         script, named after the input file — see below).
 *   --help                Show this message.
 *
 * Rows that fail validation (missing required fields, bad enum values,
 * malformed UUIDs, etc.) are written to the error log and skipped; every
 * other row is still built and included in the output. stderr only gets a
 * one-line summary pointing at the log; row-level detail lives there, not
 * on the console. The script exits 1 if any row was skipped, 0 otherwise.
 *
 * Every run creates a brand-new log file (never appended to) — by default
 * logs/{input basename}_{timestamp}_{random}.log, so logs from separate
 * runs against the same input file never collide or overwrite each other.
 * Pass --error-log=PATH to control the exact location instead.
 *
 * ---------------------------------------------------------------------------
 * Field mapping file
 * ---------------------------------------------------------------------------
 *
 * The correspondence between legacy input columns and FOLIO organization
 * fields lives in a separate JSON file (organization_field_mapping.json),
 * not in this script, so it can be edited without touching code. Format:
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
 * For each FOLIO field, at row-processing time:
 *   1. If "value" is non-empty, that value is used verbatim (hard-coded),
 *      regardless of what's in the input file.
 *   2. Otherwise, the input column named by "legacy_field" is used, if
 *      present and non-empty in that row.
 *   3. Otherwise, the input column named by "fallback_legacy_field" is
 *      used, if present and non-empty.
 *   4. Otherwise the field is left unset for that row.
 * "legacy_field"/"fallback_legacy_field" of "" or "Not mapped" are treated
 * as absent. Column name matching is case-insensitive.
 *
 * `folio_field` for a nested "primary" group field (see below) uses dot
 * notation, e.g. "addresses.city", "phoneNumbers.phoneNumber". The bundled
 * organization_field_mapping.json has one entry per field this script
 * understands, defaulting "legacy_field" to the same flat-file column
 * names documented below — edit that file to point at your legacy system's
 * actual column names, add fallbacks, or hard-code values, instead of
 * editing this script.
 *
 * ---------------------------------------------------------------------------
 * FOLIO fields this script understands
 * ---------------------------------------------------------------------------
 *
 * Top-level scalar fields (schema property name): id, name, code, status,
 * description, exportToAccounting, language, isVendor, isDonor, sanCode,
 * erpCode, paymentMethod, accessProvider, governmental, licensor,
 * materialSupplier, taxId, liableForVat, taxPercentage, claimingInterval,
 * discountPercent, expectedActivationInterval, expectedInvoiceInterval,
 * expectedReceiptInterval, renewalActivationInterval, subscriptionInterval.
 * `name`, `code`, and `status` are required by the schema; `status` must be
 * one of Active/Inactive/Pending.
 *
 * Top-level list fields — the resolved cell value holds multiple values
 * separated by --list-delimiter (default `|`): organizationTypes,
 * acqUnitIds, vendorCurrencies, contacts, privilegedContacts, interfaces.
 * All except vendorCurrencies are lists of UUIDs and are validated as such.
 *
 * Nested "primary" groups — this script supports exactly one address, one
 * phone number, one email, one URL, and one alias per organization (the
 * common bulk-import case). Any group with at least one non-empty field is
 * included and (where the schema supports it) flagged isPrimary=true:
 *
 *   addresses.addressLine1, addresses.addressLine2, addresses.city,
 *   addresses.stateRegion, addresses.zipCode, addresses.country,
 *   addresses.language, addresses.categories (UUID list)
 *
 *   phoneNumbers.phoneNumber (required if the group is present),
 *   phoneNumbers.type (Office|Mobile|Fax|Other), phoneNumbers.language,
 *   phoneNumbers.categories (UUID list)
 *
 *   emails.value (required), emails.description, emails.language,
 *   emails.categories (UUID list)
 *
 *   urls.value (required), urls.description, urls.language, urls.notes,
 *   urls.categories (UUID list)
 *
 *   aliases.value (required), aliases.description
 *
 * Not supported by this flat-file mapping (because they don't fit a single
 * "one row per organization" shape): agreements, accounts, edi, changelogs,
 * tags, metadata. Add these to the generated JSON yourself if needed.
 */

require __DIR__ . '/../phpFolioClient2/vendor/autoload.php';

use phpFolioClient\FolioUtils;

const SCALAR_FIELDS = [
    'id' => 'string',
    'name' => 'string',
    'code' => 'string',
    'status' => 'string',
    'description' => 'string',
    'exportToAccounting' => 'bool',
    'language' => 'string',
    'isVendor' => 'bool',
    'isDonor' => 'bool',
    'sanCode' => 'string',
    'erpCode' => 'string',
    'paymentMethod' => 'string',
    'accessProvider' => 'bool',
    'governmental' => 'bool',
    'licensor' => 'bool',
    'materialSupplier' => 'bool',
    'taxId' => 'string',
    'liableForVat' => 'bool',
    'taxPercentage' => 'number',
    'claimingInterval' => 'int',
    'discountPercent' => 'number',
    'expectedActivationInterval' => 'int',
    'expectedInvoiceInterval' => 'int',
    'expectedReceiptInterval' => 'int',
    'renewalActivationInterval' => 'int',
    'subscriptionInterval' => 'int',
];

const LIST_FIELDS = [
    'organizationTypes' => 'uuid',
    'acqUnitIds' => 'uuid',
    'vendorCurrencies' => 'string',
    'contacts' => 'uuid',
    'privilegedContacts' => 'uuid',
    'interfaces' => 'uuid',
];

const NESTED_GROUPS = [
    'alias' => [
        'schemaKey' => 'aliases',
        'required' => ['value'],
        'fields' => ['value' => 'string', 'description' => 'string'],
    ],
    'address' => [
        'schemaKey' => 'addresses',
        'required' => [],
        'fields' => [
            'addressLine1' => 'string',
            'addressLine2' => 'string',
            'city' => 'string',
            'stateRegion' => 'string',
            'zipCode' => 'string',
            'country' => 'string',
            'language' => 'string',
            'categories' => 'uuid_list',
        ],
        'primaryFlag' => true,
    ],
    'phone' => [
        'schemaKey' => 'phoneNumbers',
        'required' => ['phoneNumber'],
        'fields' => [
            'phoneNumber' => 'string',
            'type' => 'string',
            'language' => 'string',
            'categories' => 'uuid_list',
        ],
        'primaryFlag' => true,
        'enums' => ['type' => ['Office', 'Mobile', 'Fax', 'Other']],
    ],
    'email' => [
        'schemaKey' => 'emails',
        'required' => ['value'],
        'fields' => [
            'value' => 'string',
            'description' => 'string',
            'language' => 'string',
            'categories' => 'uuid_list',
        ],
        'primaryFlag' => true,
    ],
    'url' => [
        'schemaKey' => 'urls',
        'required' => ['value'],
        'fields' => [
            'value' => 'string',
            'description' => 'string',
            'language' => 'string',
            'notes' => 'string',
            'categories' => 'uuid_list',
        ],
        'primaryFlag' => true,
        'pattern' => ['value' => '/^(([Hh][Tt][Tt][Pp]|[Ff][Tt][Pp])([Ss])?:\/\/.+)$/'],
    ],
];

const STATUS_VALUES = ['Active', 'Inactive', 'Pending'];

function printHelp(): void {
    $source = file_get_contents(__FILE__);
    // Print the file's own leading doc-comment as the help text.
    if (preg_match('#/\*\*(.*?)\*/#s', (string) $source, $m)) {
        $lines = explode("\n", $m[1]);
        foreach ($lines as $line) {
            echo ltrim(rtrim($line), " \t*") . "\n";
        }
    }
}

function parseArgs(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
        } else {
            $key = $arg;
            $value = true;
        }
        $options[$key] = $value;
    }
    return $options;
}

function resolveDelimiter(string $raw): string {
    return match (strtolower($raw)) {
        'tab' => "\t",
        'pipe' => '|',
        'semicolon' => ';',
        'comma' => ',',
        default => $raw,
    };
}

/**
 * Build a fresh, collision-resistant default log path for one run, named
 * after the input file so multiple inputs' logs are easy to tell apart.
 */
function defaultErrorLogPath(string $inputPath): string {
    $base = pathinfo($inputPath, PATHINFO_FILENAME);
    $timestamp = date('Ymd_His');
    $unique = bin2hex(random_bytes(3));
    return __DIR__ . "/logs/{$base}_{$timestamp}_{$unique}.log";
}

/**
 * Create a directory (recursively) if it doesn't already exist.
 */
function ensureDirectoryExists(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create directory '$dir'");
    }
}

/**
 * Load the legacy-column-to-FOLIO-field mapping file and index it by
 * (lowercased) folio_field for quick lookup.
 *
 * @return array<string, array<string, mixed>>
 */
function loadMapping(string $path): array {
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
    return $index;
}

/**
 * Resolve the raw (pre-cast) value for a FOLIO field on one row, per the
 * mapping file's rules: a non-empty "value" hard-codes the result;
 * otherwise "legacy_field" is tried against the row, falling back to
 * "fallback_legacy_field" if that column is absent/empty. Returns null if
 * nothing applies.
 */
function resolveRawValue(string $folioField, array $mappingIndex, array $row): ?string {
    $entry = $mappingIndex[strtolower($folioField)] ?? null;
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

function parseBool(string $raw): ?bool {
    return match (strtolower(trim($raw))) {
        'true', 't', '1', 'yes', 'y' => true,
        'false', 'f', '0', 'no', 'n' => false,
        default => null,
    };
}

function castValue(string $raw, string $type, string $field, array &$errors, int $rowNum): mixed {
    $raw = trim($raw);
    switch ($type) {
        case 'bool':
            $value = parseBool($raw);
            if ($value === null) {
                $errors[] = "Row $rowNum: '$field' value '$raw' is not a recognized boolean (true/false/yes/no/1/0)";
            }
            return $value;
        case 'int':
            if (!preg_match('/^-?\d+$/', $raw)) {
                $errors[] = "Row $rowNum: '$field' value '$raw' is not a valid integer";
                return null;
            }
            return (int) $raw;
        case 'number':
            if (!is_numeric($raw)) {
                $errors[] = "Row $rowNum: '$field' value '$raw' is not a valid number";
                return null;
            }
            return (float) $raw;
        default:
            return $raw;
    }
}

function splitList(string $raw, string $listDelimiter): array {
    $items = array_map('trim', explode($listDelimiter, $raw));
    return array_values(array_filter($items, static fn($v) => $v !== ''));
}

/**
 * @return array{object: array, errors: string[]}
 */
function buildOrganization(array $row, array $mappingIndex, FolioUtils $utils, int $rowNum, string $listDelimiter): array {
    $errors = [];
    $org = [];

    foreach (SCALAR_FIELDS as $field => $type) {
        $raw = resolveRawValue($field, $mappingIndex, $row);
        if ($raw === null || trim($raw) === '') {
            continue;
        }
        $value = castValue($raw, $type, $field, $errors, $rowNum);
        if ($value !== null) {
            $org[$field] = $value;
        }
    }

    foreach (LIST_FIELDS as $field => $itemType) {
        $raw = resolveRawValue($field, $mappingIndex, $row);
        if ($raw === null || trim($raw) === '') {
            continue;
        }
        $items = splitList($raw, $listDelimiter);
        if ($itemType === 'uuid') {
            foreach ($items as $item) {
                if (!$utils->isValidUuid($item)) {
                    $errors[] = "Row $rowNum: '$field' contains invalid UUID '$item'";
                }
            }
        }
        if ($items) {
            $org[$field] = $items;
        }
    }

    foreach (NESTED_GROUPS as $spec) {
        $schemaKey = $spec['schemaKey'];
        $sub = [];
        foreach ($spec['fields'] as $subField => $subType) {
            $fullFieldName = "{$schemaKey}.{$subField}";
            $raw = resolveRawValue($fullFieldName, $mappingIndex, $row);
            if ($raw === null || trim($raw) === '') {
                continue;
            }

            if ($subType === 'uuid_list') {
                $items = splitList($raw, $listDelimiter);
                foreach ($items as $item) {
                    if (!$utils->isValidUuid($item)) {
                        $errors[] = "Row $rowNum: '$fullFieldName' contains invalid UUID '$item'";
                    }
                }
                if ($items) {
                    $sub[$subField] = $items;
                }
                continue;
            }

            $value = castValue($raw, $subType, $fullFieldName, $errors, $rowNum);
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
            continue;
        }

        foreach ($spec['required'] as $requiredField) {
            if (!isset($sub[$requiredField]) || $sub[$requiredField] === '') {
                $errors[] = "Row $rowNum: '$schemaKey' group is missing required field '$schemaKey.$requiredField'";
            }
        }

        if (!empty($spec['primaryFlag'])) {
            $sub['isPrimary'] = true;
        }

        $org[$schemaKey] = [$sub];
    }

    foreach (['name', 'code', 'status'] as $requiredField) {
        if (empty($org[$requiredField])) {
            $errors[] = "Row $rowNum: missing required field '$requiredField'";
        }
    }
    if (isset($org['code']) && !preg_match('/^[\S ]+$/', (string) $org['code'])) {
        $errors[] = "Row $rowNum: 'code' must not be blank or contain tabs/newlines";
    }
    if (isset($org['status']) && !in_array($org['status'], STATUS_VALUES, true)) {
        $errors[] = "Row $rowNum: 'status' value '{$org['status']}' is not one of: " . implode(', ', STATUS_VALUES);
    }
    if (isset($org['id']) && !$utils->isValidUuid((string) $org['id'])) {
        $errors[] = "Row $rowNum: 'id' value '{$org['id']}' is not a valid UUID";
    }

    return ['object' => $org, 'errors' => $errors];
}

function main(array $argv): int {
    $options = parseArgs($argv);

    if (isset($options['help'])) {
        printHelp();
        return 0;
    }

    if (empty($options['input']) || $options['input'] === true) {
        fwrite(STDERR, "Error: --input=PATH is required.\n\n");
        printHelp();
        return 1;
    }

    $inputPath = (string) $options['input'];
    if (!is_readable($inputPath)) {
        fwrite(STDERR, "Error: cannot read input file '$inputPath'\n");
        return 1;
    }

    $delimiter = resolveDelimiter((string) ($options['delimiter'] ?? 'tab'));
    $enclosure = (string) ($options['enclosure'] ?? '"');
    $listDelimiter = resolveDelimiter((string) ($options['list-delimiter'] ?? '|'));
    $format = (string) ($options['format'] ?? 'ndjson');
    $outputPath = isset($options['output']) && $options['output'] !== true ? (string) $options['output'] : null;
    $mappingPath = isset($options['mapping']) && $options['mapping'] !== true
        ? (string) $options['mapping']
        : __DIR__ . '/organization_field_mapping.json';

    if (!in_array($format, ['json', 'ndjson'], true)) {
        fwrite(STDERR, "Error: --format must be 'json' or 'ndjson'\n");
        return 1;
    }
    if (strlen($delimiter) !== 1 || strlen($enclosure) !== 1) {
        fwrite(STDERR, "Error: --delimiter and --enclosure must each resolve to a single character\n");
        return 1;
    }

    try {
        $mappingIndex = loadMapping($mappingPath);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }

    $errorLogPath = isset($options['error-log']) && $options['error-log'] !== true
        ? (string) $options['error-log']
        : defaultErrorLogPath($inputPath);

    try {
        ensureDirectoryExists(dirname($errorLogPath));
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }
    $errorLogHandle = fopen($errorLogPath, 'w');
    if ($errorLogHandle === false) {
        fwrite(STDERR, "Error: cannot write to error log '$errorLogPath'\n");
        return 1;
    }
    fwrite($errorLogHandle, sprintf(
        "Run started %s | input=%s | mapping=%s\n\n",
        date('c'),
        $inputPath,
        $mappingPath
    ));

    $handle = fopen($inputPath, 'r');
    if ($handle === false) {
        fwrite(STDERR, "Error: cannot open input file '$inputPath'\n");
        fclose($errorLogHandle);
        return 1;
    }

    $headerRow = fgetcsv($handle, 0, $delimiter, $enclosure, '');
    if ($headerRow === false || $headerRow === null) {
        fwrite(STDERR, "Error: input file '$inputPath' is empty\n");
        fclose($handle);
        fclose($errorLogHandle);
        return 1;
    }
    $headers = array_map(static fn($h) => strtolower(trim((string) $h)), $headerRow);

    $utils = new FolioUtils();
    $organizations = [];
    $rowNum = 1;
    $hadErrors = false;

    while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, '')) !== false) {
        $rowNum++;
        if ($row === [null]) {
            continue; // blank line
        }
        if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue; // fully blank row
        }

        $rowAssoc = [];
        foreach ($headers as $i => $header) {
            if ($header === '') {
                continue;
            }
            $rowAssoc[$header] = $row[$i] ?? null;
        }

        $result = buildOrganization($rowAssoc, $mappingIndex, $utils, $rowNum, $listDelimiter);
        if (!empty($result['errors'])) {
            $hadErrors = true;
            foreach ($result['errors'] as $error) {
                fwrite($errorLogHandle, $error . "\n");
            }
            fwrite($errorLogHandle, "Row $rowNum skipped due to validation errors.\n\n");
            continue;
        }

        $organizations[] = $result['object'];
    }
    fclose($handle);

    $outputHandle = $outputPath !== null ? fopen($outputPath, 'w') : STDOUT;
    if ($outputHandle === false) {
        fwrite(STDERR, "Error: cannot write to output file '$outputPath'\n");
        fclose($errorLogHandle);
        return 1;
    }

    if ($format === 'ndjson') {
        foreach ($organizations as $org) {
            fwrite($outputHandle, json_encode($org, JSON_UNESCAPED_SLASHES) . "\n");
        }
    } else {
        fwrite($outputHandle, json_encode($organizations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    if ($outputPath !== null) {
        fclose($outputHandle);
    }

    $summary = sprintf('Built %d organization(s).', count($organizations));
    fwrite($errorLogHandle, $summary . "\n");
    fclose($errorLogHandle);

    fwrite(STDERR, $summary . "\n");
    if ($hadErrors) {
        fwrite(STDERR, "Some rows were skipped due to validation errors — see: $errorLogPath\n");
    }

    return $hadErrors ? 1 : 0;
}

exit(main($argv));
