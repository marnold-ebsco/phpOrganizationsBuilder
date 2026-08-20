<?php declare(strict_types=1);

/**
 * cleanup_folio.php
 *
 * Removes everything a load_to_folio.php run loaded into a tenant,
 * using the cleanup log that script writes (see its own docblock's
 * `--cleanup-log` option) as the list of what to delete. Reading that
 * log back — rather than the records' own locally-computed ids — is
 * what makes this safe for endpoints (currently `/note-types` and
 * `/notes`) where FOLIO assigns its own id instead of honoring the one
 * sent: the log already resolved that, tenant id first.
 *
 * Deletes in the reverse of load_to_folio.php's own load order —
 * credentials, interfaces, contacts, notes, organizations, note types,
 * organization types, categories — so nothing is deleted while
 * something else loaded in the same run still references it. A
 * credential has no id of its own to delete by; it's addressed
 * entirely by its interface's id (see load_to_folio.php's own
 * docblock), so each line under that heading is treated as an
 * interface id and the whole `.../credentials` sub-resource for that
 * interface is deleted.
 *
 * This is NOT limited to records this exact log describes — if the
 * log's cleanup log path or --endpoints selection includes an
 * endpoint, every id listed under it is deleted, whether or not it's
 * still present, still owned by the tenant this log was written
 * against, or has since been modified. Double-check the log and the
 * `--folio-config` you're pointing at before confirming.
 *
 * Usage:
 *   php cleanup_folio.php [options]
 *
 * Options:
 *   --log=PATH            Cleanup log to read (written by
 *                          load_to_folio.php's own --cleanup-log).
 *                          Prompted for interactively if not given.
 *   --folio-config=PATH   FolioConfig INI file for the tenant to
 *                          delete from. Prompted for interactively if
 *                          not given.
 *   --endpoints=LIST       Comma-separated list of endpoint headings,
 *                          exactly as they appear in the cleanup log
 *                          (e.g. "/organizations-storage/organizations"),
 *                          to restrict deletion to. Default: every
 *                          endpoint found in the log. A name that
 *                          isn't in the log is warned about and ignored.
 *   --activity-log=PATH    Where to record what this run actually
 *                          deleted (default: a fresh, timestamped file
 *                          under logs/ next to this script).
 *   --yes                  Skip the "proceed?" confirmation prompt —
 *                          for scripting; the endpoint/count summary is
 *                          still printed first either way.
 *   --help                 Show this message.
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
 * The heading load_to_folio.php's cleanup log uses for credentials —
 * a template, not a real endpoint, since every credential's real
 * endpoint is nested under a different interface id.
 */
const CREDENTIALS_HEADING = '/organizations-storage/interfaces/{interfaceId}/credentials';

/**
 * Deletion order: the exact reverse of load_to_folio.php's own PHASES
 * load order.
 */
const DELETE_ORDER = [
    CREDENTIALS_HEADING,
    '/organizations-storage/interfaces',
    '/organizations-storage/contacts',
    '/notes',
    '/organizations-storage/organizations',
    '/note-types',
    '/organizations-storage/organization-types',
    '/organizations-storage/categories',
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
 * Parse a cleanup log into endpoint => list of real (tenant-assigned)
 * ids to delete. A line with a tab (`tenantId\tscriptId`, for a record
 * whose real id differs from the one load_to_folio.php sent) uses only
 * the first field — that's the one the tenant actually recognizes.
 *
 * @return array<string, list<string>>
 */
function parseCleanupLog(string $path): array {
    $sections = [];
    $current = null;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^== (.+) ==$/', $line, $m) === 1) {
            $current = $m[1];
            $sections[$current] ??= [];
            continue;
        }
        if ($current === null) {
            continue; // stray line before any heading - ignore
        }
        $sections[$current][] = explode("\t", $line, 2)[0];
    }
    return $sections;
}

/** Print a question to stdout and read one trimmed line of input. */
function prompt(string $question): string {
    fwrite(STDOUT, $question);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function main(array $argv): int {
    $options = Options::parse($argv);

    if (isset($options['help'])) {
        printHelp();
        return 0;
    }

    $logPath = isset($options['log']) && $options['log'] !== true
        ? (string) $options['log']
        : prompt('Path to the cleanup log to use: ');
    if ($logPath === '' || !is_file($logPath)) {
        fwrite(STDERR, "Error: cleanup log not found: '$logPath'\n");
        return 1;
    }

    $folioConfigPath = isset($options['folio-config']) && $options['folio-config'] !== true
        ? (string) $options['folio-config']
        : prompt('Path to the FOLIO config (folio.ini) for the tenant to clean up: ');
    if ($folioConfigPath === '' || !is_file($folioConfigPath)) {
        fwrite(STDERR, "Error: FOLIO config not found: '$folioConfigPath'\n");
        return 1;
    }

    $sections = parseCleanupLog($logPath);
    if ($sections === []) {
        fwrite(STDERR, "Error: no endpoints found in '$logPath' — nothing to clean up.\n");
        return 1;
    }

    $requestedEndpoints = null;
    if (isset($options['endpoints']) && $options['endpoints'] !== true) {
        $requestedEndpoints = array_map('trim', explode(',', (string) $options['endpoints']));
        foreach ($requestedEndpoints as $ep) {
            if (!isset($sections[$ep])) {
                fwrite(STDERR, "Warning: --endpoints named '$ep', which isn't in the log — ignoring it.\n");
            }
        }
    }

    // Delete in the fixed, dependency-safe order; anything the log has
    // under a heading this script doesn't otherwise recognize is still
    // included, appended after the known ones, so it isn't silently dropped.
    $orderedEndpoints = array_unique(array_merge(DELETE_ORDER, array_keys($sections)));
    $targetEndpoints = array_values(array_filter($orderedEndpoints, function (string $ep) use ($sections, $requestedEndpoints) {
        if (!isset($sections[$ep])) {
            return false;
        }
        return $requestedEndpoints === null || in_array($ep, $requestedEndpoints, true);
    }));

    if ($targetEndpoints === []) {
        fwrite(STDERR, "Nothing to delete — no requested endpoint matched the log.\n");
        return 1;
    }

    fwrite(STDOUT, "This will delete the following, from the tenant configured in '$folioConfigPath':\n");
    foreach ($targetEndpoints as $ep) {
        $count = count($sections[$ep]);
        fwrite(STDOUT, sprintf("  %s (%d record%s)\n", $ep, $count, $count === 1 ? '' : 's'));
    }
    fwrite(STDOUT, "\n");

    if (!isset($options['yes'])) {
        $answer = strtolower(prompt('Proceed with deletion? [y/N]: '));
        if (!in_array($answer, ['y', 'yes'], true)) {
            fwrite(STDOUT, "Aborted — nothing was deleted.\n");
            return 0;
        }
    }

    $activityLogPath = isset($options['activity-log']) && $options['activity-log'] !== true
        ? (string) $options['activity-log']
        : ErrorLog::defaultPathFor($logPath, PROJECT_ROOT . '/logs', 'cleanup_activity');

    $activityLog = new ErrorLog($activityLogPath);
    try {
        $activityLog->open();
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }
    $activityLog->write(sprintf('Run started %s', date('c')));
    $activityLog->write("Cleanup log: $logPath");
    $activityLog->write("FOLIO config: $folioConfigPath");
    $activityLog->write('');

    try {
        $config = new FolioConfig($folioConfigPath);
        $auth = new FolioAuth($config);
        $client = new FolioClient($config, $auth, new FolioUtils());
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Error: could not set up FOLIO connection: ' . $e->getMessage() . "\n");
        $activityLog->write('Error: could not set up FOLIO connection: ' . $e->getMessage());
        $activityLog->close();
        return 1;
    }

    $hadErrors = false;
    foreach ($targetEndpoints as $ep) {
        $ids = $sections[$ep];
        $activityLog->write("== {$ep} ==");
        $deleted = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $isCredential = $ep === CREDENTIALS_HEADING;
            $endpoint = $isCredential ? "/organizations-storage/interfaces/{$id}/credentials" : $ep;
            $deleteId = $isCredential ? null : $id;

            try {
                $client->delete($endpoint, $deleteId);
                $deleted++;
                $activityLog->write("Deleted {$id} ({$endpoint})");
            } catch (\Throwable $e) {
                $failed++;
                $hadErrors = true;
                $activityLog->write("Failed to delete {$id} ({$endpoint}): " . $e->getMessage());
            }
        }

        $activityLog->write(sprintf(
            'Deleted %d of %d for %s%s.',
            $deleted, count($ids), $ep, $failed > 0 ? " ($failed failed)" : ''
        ));
        $activityLog->write('');
        fwrite(STDOUT, sprintf(
            "Deleted %d of %d for %s%s.\n",
            $deleted, count($ids), $ep, $failed > 0 ? " ($failed failed)" : ''
        ));
    }

    $activityLog->write(sprintf('Run complete%s.', $hadErrors ? ' (some deletions failed — see above)' : ''));
    $activityLog->close();

    fwrite(STDOUT, "\nActivity log written to: $activityLogPath\n");
    if ($hadErrors) {
        fwrite(STDERR, "Some deletions failed — see: $activityLogPath\n");
    }

    return $hadErrors ? 1 : 0;
}

exit(main($argv));
