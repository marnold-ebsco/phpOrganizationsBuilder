<?php declare(strict_types=1);

namespace Organizations\Schema;

/**
 * Static description of the FOLIO mod-organizations-storage `contact`
 * schema (a contact *person*, not to be confused with the UUID references
 * in {@see OrganizationSchema::LIST_FIELDS}'s `contacts` field):
 * https://github.com/folio-org/acq-models/blob/master/mod-orgs/schemas/contact.json
 *
 * Reuses the exact same phoneNumbers/emails/addresses/urls nested-group
 * shapes as {@see OrganizationSchema}, since contact.json references the
 * identical sub-schemas (phone_number.json, email.json, address.json,
 * url.json).
 *
 * Two template columns have no home here and are dropped rather than
 * guessed at: a contact's job "TITLE" and a per-contact free-text
 * "DESCRIPTION" aren't properties of the real `contact` schema at all.
 */
final class ContactSchema {
    public const SCALAR_FIELDS = [
        'id' => 'string',
        'prefix' => 'string',
        'firstName' => 'string',
        'lastName' => 'string',
        'language' => 'string',
        'notes' => 'string',
        'inactive' => 'bool',
    ];

    /**
     * `categories` resolves through the shared
     * {@see \Organizations\ReferenceRegistry} (namespace `category`),
     * same as the nested groups' own `categories` fields below.
     */
    public const LIST_FIELDS = [
        'categories' => 'ref:category',
    ];

    public const NESTED_GROUPS = [
        'addresses' => OrganizationSchema::NESTED_GROUPS['addresses'],
        'phoneNumbers' => OrganizationSchema::NESTED_GROUPS['phoneNumbers'],
        'emails' => OrganizationSchema::NESTED_GROUPS['emails'],
        'urls' => OrganizationSchema::NESTED_GROUPS['urls'],
    ];

    public const REQUIRED_FIELDS = ['firstName', 'lastName'];

    public const TOP_LEVEL_ENUMS = [];

    public const TOP_LEVEL_PATTERNS = [];

    /** Allowed values for each item of an enum-constrained list field (none for contact). */
    public const LIST_FIELD_ENUMS = [];

    private function __construct() {
        // Static data holder; never instantiated.
    }
}
