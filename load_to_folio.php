<?php declare(strict_types=1);

/**
 * load_to_folio.php
 *
 * Loads the 8 output files bin/build-organizations produces into a live
 * FOLIO tenant, in the dependency-respecting order documented in
 * README.md ("Loading the output into FOLIO"):
 *
 *   1. categories.json           -> POST /organizations-storage/categories
 *   2. organization_types.json   -> POST /organizations-storage/organization-types
 *   3. note_types.json           -> POST /note-types
 *   4. organizations.json        -> POST /organizations-storage/organizations
 *   5. notes.json                -> POST /notes
 *   6. contacts.json             -> POST /organizations-storage/contacts
 *   7. interfaces.json           -> POST /organizations-storage/interfaces
 *   8. credentials.json          -> POST /organizations-storage/interfaces/{interfaceId}/credentials
 *
 * This is a deliberately separate script from bin/build-organizations
 * and process_template.php — those only ever build/validate JSON and
 * never touch a FOLIO instance; this one only ever POSTs already-built
 * JSON and never builds or validates anything. Each object is sent
 * exactly as found in its file, including its pre-assigned `id` (every
 * organization has one — see README.md — and, for credentials,
 * `interfaceId`) — see README.md for why reusing those ids (rather than
 * letting FOLIO generate new ones) is required for `credentials.json`
 * to correctly reference `interfaces.json`, and `notes.json` to
 * correctly reference `organizations.json` via each note's `links[].id`.
 *
 * One exception: FOLIO's `/note-types` endpoint always assigns its own
 * id on create, ignoring whatever id is in the POST body (confirmed
 * against a live tenant — every other endpoint here does honor a
 * client-supplied one). Since `notes.json`'s `typeId` values were
 * computed locally and won't match FOLIO's real id, this script tracks
 * each note type's real id as it's created and rewrites every note's
 * `typeId` to match before posting it — the one place a record is
 * *not* sent exactly as found in its file. A note type that fails to
 * load has no real id to substitute, so any note referencing it is
 * sent with its original (now-invalid) `typeId` and fails too.
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
 *   --input-dir=PATH            Directory holding the 8 files described
 *                               above (default: current directory).
 *   --categories=PATH           Override individual file paths (default:
 *   --organization_types=PATH   "{input-dir}/{name}.json" for each, matching
 *   --note_types=PATH           bin/build-organizations's own default
 *   --organizations=PATH        filenames). Note the underscore in
 *   --notes=PATH                 --organization_types/--note_types (not a
 *   --contacts=PATH              hyphen) — it must match the phase's file
 *   --interfaces=PATH            basename exactly, same as every other
 *   --credentials=PATH           override here.
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
 * (0 records), since not every run necessarily produces all 8 (e.g. no
 * organization used any category, no interface had credentials, or no
 * row had a note).
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
    ['note_types', '/note-types', 'note types'],
    ['organizations', '/organizations-storage/organizations', 'organizations'],
    ['notes', '/notes', 'notes'], // must load after organizations - each note's links[].id names one
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
        'note_types' => (string) ($record['name'] ?? '(no name)'),
        'organizations' => (string) ($record['code'] ?? $record['name'] ?? '(no code)'),
        'notes' => (string) ($record['title'] ?? '(no title)'),
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

    // FOLIO's /note-types endpoint always assigns its own id on create,
    // silently ignoring whatever id is in the POST body — unlike every
    // other endpoint this script talks to (organizations, interfaces,
    // etc., which do honor a client-supplied id). Since notes.json's
    // `typeId` values were computed locally by bin/build-organizations
    // (see README.md's "Reference data" section) and won't match
    // FOLIO's real id, this maps each note type's original (file) id to
    // whatever real id FOLIO actually returned when it was created, so
    // the notes phase below can rewrite each note's `typeId` before
    // posting it. A note type that failed to load at all has no entry
    // here, so any note referencing it is posted with its original,
    // unmapped typeId — which will itself fail (FOLIO won't recognize
    // it), logged the same way as any other per-record failure.
    $noteTypeIdRemap = [];

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

            if ($phaseFile === 'notes' && isset($record['typeId'], $noteTypeIdRemap[$record['typeId']])) {
                $originalTypeId = $record['typeId'];
                $record['typeId'] = $noteTypeIdRemap[$originalTypeId];
                $errorLog->write("Remapped typeId {$originalTypeId} to {$record['typeId']} (FOLIO's real note-type id).");
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
                // Can't preview the note-type remap above: it depends on
                // an id FOLIO hasn't assigned yet, since nothing is
                // actually POSTed in a dry run. A note's typeId shown
                // here is always the original, locally-computed one.
                $errorLog->write("[DRY RUN] Would POST {$label2} to {$endpoint}: " . json_encode($record, JSON_UNESCAPED_SLASHES));
                $created++;
                continue;
            }

            try {
                $response = $client->post($endpoint, $record);
                $created++;
                if ($phaseFile === 'note_types' && isset($record['id']) && isset($response->id)) {
                    $noteTypeIdRemap[(string) $record['id']] = (string) $response->id;
                }
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
