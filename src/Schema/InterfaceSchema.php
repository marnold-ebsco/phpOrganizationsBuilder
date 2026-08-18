<?php declare(strict_types=1);

namespace Organizations\Schema;

/**
 * Static description of the FOLIO mod-organizations-storage `interface`
 * schema:
 * https://github.com/folio-org/acq-models/blob/master/mod-orgs/schemas/interface.json
 * https://s3.amazonaws.com/foliodocs/api/mod-organizations-storage/r/interface.html#organizations_storage_interfaces_post
 *
 * The real schema has no `required` fields at all (not even `name`), and
 * no `categories`. `type` is an array of plain strings, each constrained
 * to the `interface_type.json` enum (see {@see LIST_FIELD_ENUMS}) — not a
 * nested object. "USERNAME"/"PASSWORD" belong to the separate
 * {@see InterfaceCredentialSchema} resource, not this one; see
 * `buildInterfacesAndCredentials()` in bin/build-organizations for how a
 * companion credential record is built alongside an interface.
 */
final class InterfaceSchema {
    public const SCALAR_FIELDS = [
        'id' => 'string',
        'name' => 'string',
        'uri' => 'string',
        'notes' => 'string',
        'available' => 'bool',
        'deliveryMethod' => 'string',
        'statisticsFormat' => 'string',
        'locallyStored' => 'string',
        'onlineLocation' => 'string',
        'statisticsNotes' => 'string',
    ];

    /**
     * `type` is an array field; each item is validated against
     * {@see LIST_FIELD_ENUMS} rather than resolved as a UUID/reference.
     */
    public const LIST_FIELDS = [
        'type' => 'string',
    ];

    public const NESTED_GROUPS = [];

    public const REQUIRED_FIELDS = [];

    public const TOP_LEVEL_ENUMS = [
        'deliveryMethod' => ['Online', 'FTP', 'Email', 'Other'],
    ];

    /** Allowed values for each item of an enum-constrained list field. */
    public const LIST_FIELD_ENUMS = [
        'type' => ['Admin', 'End user', 'Reports', 'Orders', 'Invoices', 'Other'],
    ];

    public const TOP_LEVEL_PATTERNS = [];

    private function __construct() {
        // Static data holder; never instantiated.
    }
}
