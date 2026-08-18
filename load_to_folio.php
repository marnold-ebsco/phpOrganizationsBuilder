<?php declare(strict_types=1);

/**
 * load_to_folio.php
 *
 * Loads the 6 output files bin/build-organizations produces into a live
 * FOLIO tenant, in the dependency-respecting order documented in
 * README.md ("Loading the output into FOLIO"):
 *
 *   1. categories.json           -> POST /organizations-storage/categories
 *   2. organization_types.json   -> POST /organizations-storage/organization-types
 *   3. organizations.json        -> POST /organizations-storage/organizations
 *   4. contacts.json             -> POST /organizations-storage/contacts
 *   5. interfaces.json           -> POST /organizations-storage/interfaces
 *   6. credentials.json          -> POST /organizations-storage/interfaces/{interfaceId}/credentials
 *
 * This is a deliberately separate script from bin/build-organizations
 * and process_template.php — those only ever build/validate JSON and
 * never touch a FOLIO instance; this one only ever POSTs already-built
 * JSON and never builds or validates anything. Each object is sent
 * exactly as found in its file, including its pre-assigned `id` (and,
 * for credentials, `interfaceId`) — see README.md for why reusing those
 * ids (rather than letting FOLIO generate new ones) is required for
 * `credentials.json` to correctly reference `interfaces.json`.
 *
 * A record that fails to load (validation error from FOLIO, duplicate,
 * network issue, ...) is logged and skipped — one bad record doesn't
 * abort the rest of that file, or later files. This is NOT idempotent:
 * re-running against a tenant that already has these records will
 * generally fail those individual POSTs (likely as duplicates), which
 * is reported the same way as any other per-record failure.
 *
 * Usage:
 *   php load_to_folio.php --folio-config=folio.ini --input-dir=output/ [options]
 *   php load_to_folio.php --folio-config=folio.ini --input-dir=output/ --dry-run
 *
 * Options:
 *   --folio-config=PATH        FolioConfig INI file (okapiUrl, tenant_id,
 *                               username, password — see phpFolioClient's
 *                               FolioConfig). Required unless --dry-run.
 *   --input-dir=PATH            Directory holding the 6 files described
 *                               above (default: current directory).
 *   --categories=PATH           Override individual file paths (default:
 *   --organization-types=PATH   "{input-dir}/{name}.json" for each, matching
 *   --organizations=PATH        bin/build-organizations's own default
 *   --contacts=PATH              filenames).
 *   --interfaces=PATH
 *   --credentials=PATH
 *   --error-log=PATH             Log file for this run — one file covering
 *                               every phase, same convention as
 *                               bin/build-organizations (default: a fresh,
 *                               timestamped file under logs/ next to this
 *                               script).
 *   --dry-run                   Parse everything and log exactly what
 *                               would be POSTed (endpoint + record),
 *                               without making any network calls or
 *                               requiring --folio-config. Use this first.
 *   --help                      Show this message.
 *
 * A missing input file is not an error — that phase is simply skipped
 * (0 records), since not every run necessarily produces all 6 (e.g. no
 * organization used any category, or no interface had credentials).
 * Both single-JSON-array and one-object-per-line (ndjson) files are
 * accepted, auto-detected per file — bin/build-organizations's
 * --format option controls which one it writes, but this script reads either.
 */

require __DIR__ . '/vendor/autoload.php';

use Organizations\Cli\Options;
use Organizations\Io\ErrorLog;
use phpFolioClient\FolioAuth;
use phpFolioClient\FolioClient;
use phpFolioClient\FolioConfig;
use phpFolioClient\FolioUtils;

const PROJECT_ROOT = __DIR__;

/**
 * Each phase in load order: [file basename, FOLIO endpoint or null if
 * per-record (credentials), human label for log/stderr messages].
 */
const PHASES = [
    ['categories', '/organizations-storage/categories', 'categories'],
    ['organization_types', '/organizations-storage/organization-types', 'organization types'],
    ['organizations', '/organizations-storage/organizations', 'organizations'],
    ['contacts', '/organizations-storage/contacts', 'contacts'],
    ['interfaces', '/organizations-storage/interfaces', 'interfaces'],
    ['credentials', null, 'interface credentials'], // endpoint depends on each record's interfaceId
];

function printHelp(): void {
    $source = file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', (string) $source, $m)) {
        foreach (explode("\n", $m[1]) as $line) {
            echo ltrim(rtrim($line), " \t*") . "\n";
        }
    }
}

/**
 * Read a file of records, auto-detecting whether it's a single JSON
 * array or one JSON object per line (ndjson). Returns an empty array
 * (not an error) if the file doesn't exist or is blank.
 */
function readRecords(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $content = trim((string) file_get_contents($path));
    if ($content === '') {
        return [];
    }

    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return array_is_list($decoded) ? $decoded : [$decoded];
    }

    $records = [];
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $record = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($record)) {
            $records[] = $record;
        }
    }
    return $records;
}

/** A short human-readable label for one record, for log messages. */
function recordLabel(string $phaseFile, array $record): string {
    return match ($phaseFile) {
        'categories' => (string) ($record['value'] ?? '(no value)'),
        'organization_types' => (string) ($record['name'] ?? '(no name)'),
        'organizations' => (string) ($record['code'] ?? $record['name'] ?? '(no code)'),
        'contacts' => trim(($record['firstName'] ?? '') . ' ' . ($record['lastName'] ?? '')) ?: '(no name)',
        'interfaces' => (string) ($record['name'] ?? '(no name)'),
        'credentials' => (string) ($record['username'] ?? '(no username)') . ' (interfaceId=' . ($record['interfaceId'] ?? '?') . ')',
        default => '(record)',
    };
}

/**
 * Resolve the endpoint for one record. Every phase except credentials
 * uses a fixed endpoint; a credential's endpoint is nested under the
 * specific interface it belongs to.
 */
function endpointFor(string $phaseFile, ?string $fixedEndpoint, array $record): ?string {
    if ($fixedEndpoint !== null) {
        return $fixedEndpoint;
    }
    $interfaceId = $record['interfaceId'] ?? null;
    if (!is_string($interfaceId) || $interfaceId === '') {
        return null; // can't build the URL without it
    }
    return "/organizations-storage/interfaces/{$interfaceId}/credentials";
}

function main(array $argv): int {
    $options = Options::parse($argv);

    if (isset($options['help'])) {
        printHelp();
        return 0;
    }

    $dryRun = isset($options['dry-run']);
    $folioConfigPath = isset($options['folio-config']) && $options['folio-config'] !== true
        ? (string) $options['folio-config']
        : null;

    if (!$dryRun && $folioConfigPath === null) {
        fwrite(STDERR, "Error: --folio-config=PATH is required (unless --dry-run).\n\n");
        printHelp();
        return 1;
    }

    $inputDir = isset($options['input-dir']) && $options['input-dir'] !== true
        ? (string) $options['input-dir']
        : getcwd();

    $filePaths = [];
    foreach (PHASES as [$phaseFile]) {
        $filePaths[$phaseFile] = isset($options[$phaseFile]) && $options[$phaseFile] !== true
            ? (string) $options[$phaseFile]
            : "$inputDir/$phaseFile.json";
    }

    $errorLogPath = isset($options['error-log']) && $options['error-log'] !== true
        ? (string) $options['error-log']
        : ErrorLog::defaultPathFor($inputDir, PROJECT_ROOT . '/logs');

    $errorLog = new ErrorLog($errorLogPath);
    try {
        $errorLog->open();
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }
    $errorLog->write(sprintf('Run started %s%s', date('c'), $dryRun ? ' (DRY RUN — no network calls made)' : ''));
    $errorLog->write('');

    $client = null;
    if (!$dryRun) {
        try {
            $config = new FolioConfig($folioConfigPath);
            $auth = new FolioAuth($config);
            $client = new FolioClient($config, $auth, new FolioUtils());
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: could not set up FOLIO connection: ' . $e->getMessage() . "\n");
            $errorLog->write('Error: could not set up FOLIO connection: ' . $e->getMessage());
            $errorLog->close();
            return 1;
        }
    }

    $hadErrors = false;

    foreach (PHASES as [$phaseFile, $fixedEndpoint, $label]) {
        $path = $filePaths[$phaseFile];
        $errorLog->write("== {$label} ({$path}) ==");

        if (!is_file($path)) {
            $errorLog->write("No file found — skipping.");
            $errorLog->write('');
            fwrite(STDERR, "Skipped $label: no file at $path\n");
            continue;
        }

        $records = readRecords($path);
        $created = 0;
        $failed = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $endpoint = endpointFor($phaseFile, $fixedEndpoint, $record);
            $label2 = recordLabel($phaseFile, $record);

            if ($endpoint === null) {
                $failed++;
                $hadErrors = true;
                $errorLog->write("Skipped {$label2}: missing interfaceId, can't build credentials endpoint.");
                continue;
            }

            if ($dryRun) {
                $errorLog->write("[DRY RUN] Would POST {$label2} to {$endpoint}: " . json_encode($record, JSON_UNESCAPED_SLASHES));
                $created++;
                continue;
            }

            try {
                $client->post($endpoint, $record);
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $hadErrors = true;
                $errorLog->write("Failed to load {$label2} ({$endpoint}): " . $e->getMessage());
            }
        }

        $errorLog->write(sprintf(
            '%s %d of %d %s%s.',
            $dryRun ? 'Would load' : 'Loaded',
            $created, count($records), $label,
            $failed > 0 ? " ($failed failed)" : ''
        ));
        $errorLog->write('');
        fwrite(STDERR, sprintf(
            "%s %d of %d %s%s.\n",
            $dryRun ? 'Would load' : 'Loaded',
            $created, count($records), $label,
            $failed > 0 ? " ($failed failed)" : ''
        ));
    }

    $errorLog->write(sprintf('Run complete%s.', $hadErrors ? ' (some records failed — see above)' : ''));
    $errorLog->close();

    if ($hadErrors) {
        fwrite(STDERR, "Some records failed to load — see: $errorLogPath\n");
    }

    return $hadErrors ? 1 : 0;
}

exit(main($argv));
