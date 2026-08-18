<?php declare(strict_types=1);

namespace Organizations\Schema;

/**
 * Static description of the slice of the FOLIO mod-organizations-storage
 * `organization` schema this package understands:
 * https://s3.amazonaws.com/foliodocs/api/mod-organizations-storage/r/organization.html#organizations_storage_organizations_post
 *
 * Holds no behavior, only data — {@see \Organizations\RecordBuilder}
 * reads these constants to know which fields exist, how to cast/validate
 * each one, and how the nested groups (address/phone/email/url/alias) are
 * shaped. See {@see ContactSchema}/{@see InterfaceSchema} for the sibling
 * record types built the same way.
 */
final class OrganizationSchema {
    /** Top-level scalar fields: schema property name => cast type. */
    public const SCALAR_FIELDS = [
        'id' => 'string',
        'name' => 'string',
        'code' => 'string',
        'status' => 'string',
        'description' => 'string',
        'exportToAccounting' => 'bool',
        'language' => 'string',
        'isVendor' => 'bool',
        'isDonor' => 'bool',
        'sanCode' => 'string',
        'erpCode' => 'string',
        'paymentMethod' => 'string',
        'accessProvider' => 'bool',
        'governmental' => 'bool',
        'licensor' => 'bool',
        'materialSupplier' => 'bool',
        'taxId' => 'string',
        'liableForVat' => 'bool',
        'taxPercentage' => 'number',
        'claimingInterval' => 'int',
        'discountPercent' => 'number',
        'expectedActivationInterval' => 'int',
        'expectedInvoiceInterval' => 'int',
        'expectedReceiptInterval' => 'int',
        'renewalActivationInterval' => 'int',
        'subscriptionInterval' => 'int',
    ];

    /**
     * Top-level list fields: schema property name => list item type.
     * `ref:organizationType` resolves each item through the shared
     * {@see \Organizations\ReferenceRegistry} (namespace `organizationType`)
     * instead of validating it as a literal UUID — see
     * {@see \Organizations\RecordBuilder}.
     */
    public const LIST_FIELDS = [
        'organizationTypes' => 'ref:organizationType',
        'acqUnitIds' => 'uuid',
        'vendorCurrencies' => 'string',
        'contacts' => 'uuid',
        'privilegedContacts' => 'uuid',
        'interfaces' => 'uuid',
    ];

    /**
     * Nested groups (address/phone/email/url/alias) keyed by their schema
     * array property name. Each group can hold any number of instances
     * per organization — see {@see \Organizations\Mapping\FieldMapper::indicesFor()}
     * for how the mapping file expresses more than one. Groups with
     * `primaryFlag` support an `isPrimary` field; if no instance sets it
     * explicitly, {@see \Organizations\RecordBuilder} defaults it to true
     * on the first instance. `category_ref_list` fields resolve through
     * the shared {@see \Organizations\ReferenceRegistry} (namespace
     * `category`) instead of validating literal UUIDs.
     */
    public const NESTED_GROUPS = [
        'aliases' => [
            'required' => ['value'],
            'fields' => ['value' => 'string', 'description' => 'string'],
        ],
        'addresses' => [
            'required' => [],
            'fields' => [
                'addressLine1' => 'string',
                'addressLine2' => 'string',
                'city' => 'string',
                'stateRegion' => 'string',
                'zipCode' => 'string',
                'country' => 'string',
                'language' => 'string',
                'categories' => 'category_ref_list',
                'isPrimary' => 'bool',
            ],
            'primaryFlag' => true,
        ],
        'phoneNumbers' => [
            'required' => ['phoneNumber'],
            'fields' => [
                'phoneNumber' => 'string',
                'type' => 'string',
                'language' => 'string',
                'categories' => 'category_ref_list',
                'isPrimary' => 'bool',
            ],
            'primaryFlag' => true,
            'enums' => ['type' => ['Office', 'Mobile', 'Fax', 'Other']],
        ],
        'emails' => [
            'required' => ['value'],
            'fields' => [
                'value' => 'string',
                'description' => 'string',
                'language' => 'string',
                'categories' => 'category_ref_list',
                'isPrimary' => 'bool',
            ],
            'primaryFlag' => true,
        ],
        'urls' => [
            'required' => ['value'],
            'fields' => [
                'value' => 'string',
                'description' => 'string',
                'language' => 'string',
                'notes' => 'string',
                'categories' => 'category_ref_list',
                'isPrimary' => 'bool',
            ],
            'primaryFlag' => true,
            'pattern' => ['value' => '/^(([Hh][Tt][Tt][Pp]|[Ff][Tt][Pp])([Ss])?:\/\/.+)$/'],
        ],
        'accounts' => [
            'required' => ['name', 'accountNo', 'accountStatus'],
            'fields' => [
                'name' => 'string',
                'accountNo' => 'string',
                'description' => 'string',
                'appSystemNo' => 'string',
                'paymentMethod' => 'string',
                'accountStatus' => 'string',
                'contactInfo' => 'string',
                'libraryCode' => 'string',
                'libraryEdiCode' => 'string',
                'notes' => 'string',
            ],
            'enums' => ['paymentMethod' => ['Cash', 'Credit Card', 'EFT', 'Deposit Account', 'Physical Check', 'Bank Draft', 'Internal Transfer', 'Other']],
        ],
    ];

    /** Top-level fields the schema marks as required. */
    public const REQUIRED_FIELDS = ['name', 'code', 'status'];

    /** Allowed values for top-level enum fields. */
    public const TOP_LEVEL_ENUMS = [
        'status' => ['Active', 'Inactive', 'Pending'],
    ];

    /** Regex patterns top-level fields must match, if present. */
    public const TOP_LEVEL_PATTERNS = [
        'code' => '/^[\S ]+$/',
    ];

    /** Allowed values for each item of an enum-constrained list field (none for organization). */
    public const LIST_FIELD_ENUMS = [];

    private function __construct() {
        // Static data holder; never instantiated.
    }
}
