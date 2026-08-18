<?php declare(strict_types=1);

namespace Organizations\Schema;

/**
 * Static description of the FOLIO mod-organizations-storage
 * `interface_credential` schema:
 * https://github.com/folio-org/acq-models/blob/master/mod-orgs/schemas/interface_credential.json
 *
 * The real schema's `required` array is `["interfaceId", "username",
 * "password"]` — `interfaceId` is just as required as the other two, but
 * it can't be one of {@see REQUIRED_FIELDS} here: it's assigned
 * programmatically (see `buildInterfacesAndCredentials()` in
 * bin/build-organizations), always set on every credential record that
 * actually gets emitted, but not yet present at the point
 * {@see \Organizations\RecordBuilder::build()} runs its required-field
 * check, since it isn't (and can't be) mapped from a column — it must
 * match the very interface record this credential belongs to, which
 * doesn't exist yet while this record is being built from the row alone.
 * Only `username`/`password` come from the mapping file.
 */
final class InterfaceCredentialSchema {
    public const SCALAR_FIELDS = [
        'username' => 'string',
        'password' => 'string',
    ];

    public const LIST_FIELDS = [];

    public const NESTED_GROUPS = [];

    public const REQUIRED_FIELDS = ['username', 'password'];

    public const TOP_LEVEL_ENUMS = [];

    public const TOP_LEVEL_PATTERNS = [];

    /** Allowed values for each item of an enum-constrained list field (none for interface_credential). */
    public const LIST_FIELD_ENUMS = [];

    private function __construct() {
        // Static data holder; never instantiated.
    }
}
