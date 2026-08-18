<?php declare(strict_types=1);

namespace Organizations;

use Organizations\Io\DelimitedFileReader;
use Organizations\Io\ErrorLog;

/**
 * Orchestrates a full import run: reads every row from a
 * {@see DelimitedFileReader}, builds each one via a {@see RecordBuilder}
 * (organization, contact, or interface — whichever schema the builder
 * was constructed with), and writes any validation errors to an
 * {@see ErrorLog} instead of returning/throwing them — a bad row is
 * logged and skipped, not fatal to the rest of the run.
 *
 * The error log is expected to already be open (and is left open) —
 * this class writes one section into it, but doesn't own its lifecycle,
 * since a full bin/build-organizations run shares one log file across
 * several of these (organizations, contacts, ...) plus other build
 * phases. See {@see ErrorLog::open()}/{@see ErrorLog::close()}.
 */
final class RecordFileImporter {
    /**
     * @param $builder Builds and validates each row.
     */
    public function __construct(
        private readonly RecordBuilder $builder,
    ) {
    }

    /**
     * Run a full import: process every row of an already-open reader,
     * writing this section's validation errors to an already-open error
     * log (neither is opened or closed here).
     *
     * @param $reader   The file to import (not yet opened).
     * @param $errorLog Where to log validation errors (already open).
     * @param $sectionLabel Human-readable label for this section's log entries (e.g. `organizations`).
     * @throws \RuntimeException If the reader can't be opened.
     */
    public function import(DelimitedFileReader $reader, ErrorLog $errorLog, string $sectionLabel = 'organizations'): ImportResult {
        $errorLog->write("== {$sectionLabel} ==");

        try {
            $reader->open();
        } catch (\RuntimeException $e) {
            $errorLog->write('Error: ' . $e->getMessage());
            throw $e;
        }

        $records = [];
        $acceptedRowNumbers = [];
        $rejectedRowNumbers = [];
        $hadErrors = false;

        foreach ($reader->rows() as $rowNum => $rowAssoc) {
            $result = $this->builder->build($rowAssoc, $rowNum);
            if ($result->hasErrors()) {
                $hadErrors = true;
                $rejectedRowNumbers[] = $rowNum;
                foreach ($result->getErrors() as $error) {
                    $errorLog->write($error);
                }
                $errorLog->write("Row $rowNum skipped due to validation errors.");
                $errorLog->write('');
                continue;
            }
            $records[] = $result->getRecord();
            $acceptedRowNumbers[] = $rowNum;
        }
        $reader->close();

        $errorLog->write(sprintf('Built %d record(s).', count($records)));
        $errorLog->write('');

        return new ImportResult($records, $hadErrors, $errorLog->getPath(), $acceptedRowNumbers, $rejectedRowNumbers);
    }
}
