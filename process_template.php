<?php declare(strict_types=1);

/**
 * process_template.php
 *
 * Reads a filled-out copy of Organization_Template.xlsx (the multi-sheet
 * FOLIO organizations import workbook) and turns it into the flat,
 * indexed-column row format bin/build-organizations already understands
 * (the same convention organization_field_mapping.json documents:
 * `address2_city`, `phoneNumbers[3]`, etc.) — see
 * \Organizations\TemplateFlattener for exactly how each sheet maps.
 *
 * This script is a thin CLI wrapper: the flattening logic lives in
 * \Organizations\TemplateFlattener (unit tested independently), and once
 * the flattened rows are written to an intermediate delimited file, this
 * script shells out to the already-tested bin/build-organizations to
 * actually build every record type (organizations, contacts, interfaces,
 * categories, organization types), each to its own output file. Nothing
 * here talks to a FOLIO instance, and no loading/POSTing happens — see
 * bin/build-organizations for the one exception (--folio-config, to look
 * up *existing* reference data so new categories/organization types
 * aren't invented needlessly).
 *
 * Usage:
 *   php process_template.php --input=Organization_Template_filled.xlsx --output-dir=out/
 *
 * Options:
 *   --input=PATH        Filled xlsx template (required).
 *   --output-dir=PATH   Directory for all 6 output files (default: current directory).
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
 *   --help               Show this message.
 */

require __DIR__ . '/vendor/autoload.php';

use Organizations\Cli\Options;
use Organizations\Io\ErrorLog;
use Organizations\Io\XlsxReader;
use Organizations\TemplateFlattener;

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
    $options = Options::parse($argv);

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
    $outputDir = isset($options['output-dir']) && $options['output-dir'] !== true
        ? (string) $options['output-dir']
        : getcwd();
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

    $reader = new XlsxReader($inputPath);
    try {
        $reader->open();
    } catch (\RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }

    $flattener = new TemplateFlattener();
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

    if ($flattener->getDroppedNoteCount() > 0) {
        $message = "Note: {$flattener->getDroppedNoteCount()} row(s) on the 'Notes' sheet have no destination in the organization schema and are not included in any output.";
        fwrite(STDERR, $message . "\n");
        $errorLog->write($message);
    }
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

    // --- hand off to bin/build-organizations for the actual building -------
    // --error-log/--append-log continue the exact same file this script
    // just wrote its own section to, rather than starting a fresh one.
    $buildArgs = ['--input=' . $intermediatePath, '--error-log=' . $errorLogPath, '--append-log'];
    foreach (['mapping', 'format', 'folio-config'] as $passthroughOption) {
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

    return $exitCode;
}

exit(main($argv));
