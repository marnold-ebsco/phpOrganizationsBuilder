<?php declare(strict_types=1);

namespace Organizations\Schema;

/**
 * Static description of the FOLIO mod-notes `note` schema — a
 * completely separate module/resource from mod-organizations-storage
 * (everything else in this package's `Schema` namespace belongs to
 * that one):
 * https://s3.amazonaws.com/foliodocs/api/mod-notes/s/notes.html
 *
 * A note is generic (any domain/object can have notes attached); this
 * package only ever attaches them to organizations, so `domain` is
 * always the fixed string {@see DOMAIN} ("organizations"), assigned
 * programmatically rather than via the mapping file — see further down
 * for why. `typeId` is a UUID naming a separate `note_type` resource
 * (`{id, name}` — see the real schema's `noteType.yaml`); this package
 * resolves it the same way `organizationTypes`/`categories` resolve a
 * name to a UUID, via the shared {@see \Organizations\ReferenceRegistry}
 * (namespace `noteType`) — see {@see \Organizations\RecordBuilder}'s
 * `ref:` scalar-field handling.
 *
 * Neither `domain` nor `links` is one of {@see REQUIRED_FIELDS} here,
 * even though the real schema requires `domain` — same reason
 * `interface_credential`'s `interfaceId` isn't one of
 * {@see InterfaceCredentialSchema::REQUIRED_FIELDS}: both are assigned
 * programmatically (see `buildNotes()` in bin/build-organizations,
 * which sets them after {@see \Organizations\RecordBuilder::build()}
 * returns) rather than mapped from a column. `domain` specifically
 * can't be a plain hardcoded mapping-file `value` the way, say, a
 * constant `status` could be for some other schema: a hardcoded value
 * resolves unconditionally, which would make every note instance look
 * "non-empty" — including ones where every real column (type/title/
 * content) is blank — defeating the `empty($result->getRecord())`
 * check every multi-instance group in this codebase relies on to skip
 * an instance that doesn't apply to a given row.
 */
final class NoteSchema {
    /** The fixed `domain` (and matching `links[].type`) this package always uses. */
    public const DOMAIN = 'organizations';

    public const SCALAR_FIELDS = [
        'typeId' => 'ref:noteType',
        'title' => 'string',
        'content' => 'string',
    ];

    public const LIST_FIELDS = [];

    public const NESTED_GROUPS = [];

    /** Top-level fields the schema marks as required, excluding `domain` — see class docblock. */
    public const REQUIRED_FIELDS = ['typeId', 'title'];

    public const TOP_LEVEL_ENUMS = [];

    public const TOP_LEVEL_PATTERNS = [];

    /** Allowed values for each item of an enum-constrained list field (none for note). */
    public const LIST_FIELD_ENUMS = [];

    private function __construct() {
        // Static data holder; never instantiated.
    }
}
