<?php declare(strict_types=1);

namespace Organizations;

/**
 * Outcome of building one record (organization, contact, or interface)
 * from one input row: either a usable record array, a list of validation
 * errors, or both (a partially-built array is still returned alongside
 * errors, but callers should check {@see hasErrors()} before treating it
 * as valid).
 */
final class RecordBuildResult {
    /**
     * @param $record The built record array (schema-shaped, but not
     *                guaranteed valid if there are errors).
     * @param $errors Validation error messages, if any.
     */
    public function __construct(
        private readonly array $record,
        private readonly array $errors,
    ) {
    }

    /** @return The built record array. */
    public function getRecord(): array {
        return $this->record;
    }

    /** @return Validation error messages for this row. */
    public function getErrors(): array {
        return $this->errors;
    }

    /** @return True if this row failed validation. */
    public function hasErrors(): bool {
        return $this->errors !== [];
    }
}
