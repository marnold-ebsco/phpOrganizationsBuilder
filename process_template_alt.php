<?php declare(strict_types=1);

/**
 * process_template_alt.php
 *
 * Same job as process_template.php, but for the alternate workbook,
 * Organization_Template_Alternate.xlsx — see README_alternate.md for how
 * its sheet layout differs (Main Org record holds only fields that can't
 * repeat; every alias/address/phone/email/url, including the first one,
 * is a row on its own dedicated sheet, each with an IS PRIMARY column).
 *
 * The only difference from process_template.php is which flattener reads
 * the workbook (\Organizations\AlternateTemplateFlattener instead of
 * \Organizations\TemplateFlattener). Both produce the exact same flat,
 * indexed-column row format (`address2_city`, `phoneNumbers[3]`, etc.) —
 * see \Organizations\Mapping\FieldMapper and \Organizations\RecordBuilder
 * — so this script hands off to the *same*, unmodified
 * bin/build-organizations and the *same* organization_field_mapping.json
 * to actually build every record type. Nothing here talks to a FOLIO
 * instance, and no loading/POSTing happens — see bin/build-organizations
 * for the one exception (--folio-config, to look up *existing* reference
 * data so new categories/organization types aren't invented needlessly).
 *
 * Usage:
 *   php process_template_alt.php --input=Organization_Template_Alternate_filled.xlsx --output-dir=out/
 *
 * Options:
 *   --input=PATH        Filled alternate xlsx template. If omitted, you'll
 *                       be prompted to type a path interactively.
 *   --output-dir=PATH   Directory for all 6 output files (default: same
 *                       directory as --input).
 *   --intermediate=PATH Where to write the flattened delimited file
 *                       (default: a temp file, deleted afterward).
 *   --keep-intermediate Don't delete the intermediate file (useful for debugging).
 *   --mapping=PATH       Passed through to bin/build-organizations.
 *   --format=json|ndjson Passed through to bin/build-organizations.
 *   --error-log=PATH     This script's own flattening-stage summary (see
 *                        below) is written here first; bin/build-organizations
 *                        then appends everything else to the same file, so
 *                        the whole run still ends up in one log (default:
 *                        a fresh, timestamped file under logs/ at the
 *                        project root, same convention bin/build-organizations
 *                        itself uses).
 *   --folio-config=PATH  Passed through to bin/build-organizations.
 *   --uuid-version=...   Passed through to bin/build-organizations
 *                        verbatim (default: 5 for every endpoint) — see
 *                        that script's own docblock for the
 *                        per-endpoint "type=version" syntax.
 *   --log-uuids          Off by default. When given, writes a log of every
 *                        new record's type, id, and a short label — see
 *                        bin/build-organizations's own docblock.
 *   --uuid-log=PATH      Path for the --log-uuids file (default: a fresh,
 *                        timestamped file in --output-dir, alongside
 *                        organizations.json etc.). Ignored unless
 *                        --log-uuids is also given.
 *   --help               Show this message.
 */

require __DIR__ . '/vendor/autoload.php';

use Organizations\AlternateTemplateFlattener;
use Organizations\Cli\Options;
use Organizations\Io\ErrorLog;
use Organizations\Io\XlsxReader;

const PROJECT_ROOT = __DIR__;

function printHelp(): void {
    $source = file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', (string) $source, $m)) {
        foreach (explode("\n", $m[1]) as $line) {
            echo ltrim(rtrim($line), " \t*") . "\n";
        }
    }
}

function main(array $argv): int {
    $startTime = hrtime(true);
    $options = Options::parse($argv);

    if (isset($options['help'])) {
        printHelp();
        return 0;
    }
    if (empty($options['input']) || $options['input'] === true) {
        fwrite(STDERR, "Path to filled alternate xlsx template: ");
        $inputPath = trim((string) fgets(STDIN));
        if ($inputPath === '') {
            fwrite(STDERR, "Error: --input=PATH is required.\n\n");
            printHelp();
            return 1;
        }
    } else {
        $inputPath = (string) $options['input'];
    }
    $outputDir = isset($options['output-dir']) && $options['output-dir'] !== true
        ? (string) $options['output-dir']
        : dirname($inputPath);
    $intermediatePath = isset($options['intermediate']) && $options['intermediate'] !== true
        ? (string) $options['intermediate']
        : tempnam(sys_get_temp_dir(), 'orgs_flat_') . '.tsv';
    $keepIntermediate = isset($options['keep-intermediate']);

    if (!is_readable($inputPath)) {
        fwrite(STDERR, "Error: cannot read input file '$inputPath'\n");
        return 1;
    }
    if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        fwrite(STDERR, "Error: cannot create output directory '$outputDir'\n");
        return 1;
    }

    // Resolved now (rather than left to bin/build-organizations to pick
    // its own default) so this script's own flattening-stage summary
    // below and bin/build-organizations's later sections both land in
    // the exact same file — see --append-log further down.
    $errorLogPath = isset($options['error-log']) && $options['error-log'] !== true
        ? (string) $options['error-log']
        : ErrorLog::defaultPathFor($inputPath, PROJECT_ROOT . '/logs');
    // Off by default (bin/build-organizations ignores --uuid-log unless
    // --log-uuids is also given, so there's no point resolving a default
    // path otherwise). When on, named after the original xlsx (not the
    // intermediate tsv), and resolved here rather than left to
    // bin/build-organizations's own default, so it's timestamped like
    // --error-log but lands in --output-dir alongside organizations.json
    // etc., not project-root logs/.
    $logUuidsEnabled = isset($options['log-uuids']);
    $uuidLogPath = $logUuidsEnabled
        ? (isset($options['uuid-log']) && $options['uuid-log'] !== true
            ? (string) $options['uuid-log']
            : ErrorLog::defaultPathFor($inputPath, $outputDir, 'uuids'))
        : null;

    $reader = new XlsxReader($inputPath);
    try {
        $reader->open();
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }

    $flattener = new AlternateTemplateFlattener();
    $flatRows = $flattener->flatten($reader);
    $reader->close();

    $errorLog = new ErrorLog($errorLogPath);
    try {
        $errorLog->open();
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }
    $errorLog->write('== template flattening ==');

    if ($flatRows === []) {
        fwrite(STDERR, "Error: no organizations found on the 'Main Org record' sheet (no row has an ORG CODE).\n");
        $errorLog->write("Error: no organizations found on the 'Main Org record' sheet (no row has an ORG CODE).");
        $errorLog->close();
        return 1;
    }

    $columns = [];
    foreach ($flatRows as $flat) {
        foreach (array_keys($flat) as $column) {
            $columns[$column] = true;
        }
    }
    $columns = array_keys($columns);

    $intermediateHandle = fopen($intermediatePath, 'w');
    if ($intermediateHandle === false) {
        fwrite(STDERR, "Error: cannot write intermediate file '$intermediatePath'\n");
        $errorLog->close();
        return 1;
    }
    fwrite($intermediateHandle, implode("\t", $columns) . "\n");
    foreach ($flatRows as $flat) {
        $line = array_map(static fn($col) => $flat[$col] ?? '', $columns);
        fwrite($intermediateHandle, implode("\t", $line) . "\n");
    }
    fclose($intermediateHandle);

    $flattenedMessage = sprintf("Flattened %d organization(s) from '%s' into '%s'.", count($flatRows), $inputPath, $intermediatePath);
    fwrite(STDERR, $flattenedMessage . "\n");
    $errorLog->write($flattenedMessage);
    $errorLog->write('');
    $errorLog->close();

    // --- hand off to the same, unmodified bin/build-organizations -------
    // --error-log/--append-log continue the exact same file this script
    // just wrote its own section to, rather than starting a fresh one.
    $buildArgs = ['--input=' . $intermediatePath, '--error-log=' . $errorLogPath, '--append-log'];
    if ($logUuidsEnabled) {
        $buildArgs[] = '--log-uuids';
        $buildArgs[] = '--uuid-log=' . $uuidLogPath;
    }
    foreach (['mapping', 'format', 'folio-config', 'uuid-version'] as $passthroughOption) {
        if (isset($options[$passthroughOption]) && $options[$passthroughOption] !== true) {
            $buildArgs[] = "--{$passthroughOption}=" . $options[$passthroughOption];
        }
    }
    $buildArgs[] = '--output=' . $outputDir . '/organizations.json';

    $command = array_merge([PHP_BINARY, PROJECT_ROOT . '/bin/build-organizations'], $buildArgs);
    $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Error: could not launch bin/build-organizations\n");
        return 1;
    }
    $exitCode = proc_close($process);

    if (!$keepIntermediate) {
        unlink($intermediatePath);
    } else {
        fwrite(STDERR, "Intermediate flattened file kept at: $intermediatePath\n");
    }

    $elapsedSeconds = (hrtime(true) - $startTime) / 1_000_000_000;
    fwrite(STDERR, sprintf("Completed in %05.2f seconds.\n", $elapsedSeconds));

    return $exitCode;
}

exit(main($argv));
