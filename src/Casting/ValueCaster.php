<?php declare(strict_types=1);

namespace Organizations\Casting;

/**
 * Converts raw string cell values into typed PHP values, and splits
 * multi-value cells into lists. Holds no state of its own; every failure
 * is reported by appending a message to the caller-supplied `$errors`
 * array rather than throwing, so a single bad cell doesn't abort an
 * otherwise-valid row.
 */
final class ValueCaster {
    /**
     * Parse a string as a boolean using a small set of common
     * true/false spellings.
     *
     * @param $raw The trimmed string to parse.
     * @return True/false if recognized, or null if not.
     */
    public function parseBool(string $raw): ?bool {
        return match (strtolower(trim($raw))) {
            'true', 't', '1', 'yes', 'y' => true,
            'false', 'f', '0', 'no', 'n' => false,
            default => null,
        };
    }

    /**
     * Cast a raw cell value to the given type, recording a validation
     * error (and returning null) if it doesn't parse as that type.
     *
     * @param $raw    The raw cell value.
     * @param $type   One of `string`, `bool`, `int`, `number`.
     * @param $field  Field name, used only to phrase error messages.
     * @param $errors Error list to append to on a cast failure.
     * @param $rowNum Row number, used only to phrase error messages.
     * @return The cast value, or null if casting failed (`string`
     *         never fails).
     */
    public function cast(string $raw, string $type, string $field, array &$errors, int $rowNum): mixed {
        $raw = trim($raw);
        switch ($type) {
            case 'bool':
                $value = $this->parseBool($raw);
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

    /**
     * Split a single cell into a list of trimmed, non-empty values.
     *
     * @param $raw           The raw cell value.
     * @param $listDelimiter Delimiter separating values within the cell.
     * @return The non-empty, trimmed items found.
     */
    public function splitList(string $raw, string $listDelimiter): array {
        $items = array_map('trim', explode($listDelimiter, $raw));
        return array_values(array_filter($items, static fn($v) => $v !== ''));
    }
}
