<?php declare(strict_types=1);

namespace Organizations;

/**
 * Outcome of importing an entire delimited file: the successfully-built
 * records (organizations, contacts, or interfaces — whichever
 * {@see RecordFileImporter} was built for), whether any rows were
 * skipped, which row numbers were accepted/rejected (used for
 * orphan-detection — e.g. a contact built from a row whose organization
 * was rejected), and where the detailed error log for this run was written.
 */
final class ImportResult {
    /**
     * @param $records              Successfully-built record objects.
     * @param $hadErrors             True if any row was skipped due to validation errors.
     * @param $errorLogPath          Path of this run's error log file.
     * @param $acceptedRowNumbers    Row numbers that built successfully, in the same order as `$records`.
     * @param $rejectedRowNumbers    Row numbers skipped due to validation errors.
     */
    public function __construct(
        private readonly array $records,
        private readonly bool $hadErrors,
        private readonly string $errorLogPath,
        private readonly array $acceptedRowNumbers = [],
        private readonly array $rejectedRowNumbers = [],
    ) {
    }

    /** @return The successfully-built record objects. */
    public function getRecords(): array {
        return $this->records;
    }

    /** @return True if any row was skipped due to validation errors. */
    public function hadErrors(): bool {
        return $this->hadErrors;
    }

    /** @return Path of this run's error log file. */
    public function getErrorLogPath(): string {
        return $this->errorLogPath;
    }

    /** @return Row numbers that built successfully, in the same order as {@see getRecords()}. */
    public function getAcceptedRowNumbers(): array {
        return $this->acceptedRowNumbers;
    }

    /** @return Row numbers skipped due to validation errors. */
    public function getRejectedRowNumbers(): array {
        return $this->rejectedRowNumbers;
    }
}
