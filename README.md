# Organizations Import

## TL;DR

To process data using the alternate template (recommended):

```bash
php process_template_alt.php --input="Organization_Template_Alternate_example_data.xlsx" --output-dir=output_alt --error-log=output_alt/run.log  --folio-config=folio.ini
```

To process data using the original template:

```bash
php process_template.php --input="Organization_Template_example_data.xlsx" --output-dir=output --error-log=output/run.log  --folio-config=folio.ini
```

To process a delimited text file directly (skips both Excel templates —
see [Setting up the field mapping](#setting-up-the-field-mapping)):

```bash
mkdir -p output
php bin/build-organizations --input=your_file.tsv --mapping=path/to/your_mapping.json --output=output/organizations.json  --folio-config=folio.ini
```

Unlike the two `process_template*.php` scripts above, `bin/build-organizations`
does **not** create its output directory for you (`--output-dir` isn't
one of its options — every other output file lands next to whatever
directory `--output` is in), so `mkdir -p output` first or the run fails
outright. Each command above builds every record type it finds — including
`notes.json`, `contacts.json`, `interfaces.json`, `credentials.json`,
and the deduplicated `categories.json`/`organization_types.json`/`note_types.json` —
into the given output directory; run any of them with `--help` for the
full option list, or read on for everything else this package does.

Once you have output you're happy with, load it into a live FOLIO
tenant — always `--dry-run` first (see
[Loading the output into FOLIO](#loading-the-output-into-folio)):

```bash
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/ --dry-run
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/
```

(`folio.ini` is a FolioConfig file — `okapiUrl`, `tenant_id`, `username`,
`password`; substitute `output/` for `output_alt/` if you built with
the original template instead.)

Loading a test batch you'll want to remove later? `load_to_folio.php`
also writes a cleanup log naming every record's real id, grouped by
endpoint; `cleanup_folio.php` reads it back to delete everything (or
just the endpoints you name) — see
[Removing what you loaded](#removing-what-you-loaded).

---

Builds FOLIO mod-organizations-storage JSON objects from delimited legacy
data — organizations, contact people, interfaces, categories, and
organization types — per the real FOLIO schemas (see the acq-models repo:
https://github.com/folio-org/acq-models/tree/master/mod-orgs/schemas).
It also builds mod-notes `notes` (a completely separate FOLIO module —
see [Notes](#notes)) attached to each organization.

Building and loading are two separate scripts. `bin/build-organizations`
(and `process_template.php`) only build and validate JSON — they never
talk to FOLIO to create anything (with one narrow exception:
`--folio-config`, which *reads* existing categories/organization/note
types so it doesn't invent duplicates — see
[Reference data](#reference-data-categories--organization-types) below).
[`load_to_folio.php`](load_to_folio.php) is the one that actually POSTs
the resulting files into a live tenant, in the required order — see
[Loading the output into FOLIO](#loading-the-output-into-folio). An
organization's own `contacts`/`interfaces` arrays are already wired up
by the build step, for a contact/interface built from that same row —
see [Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)
— so loading has nothing left to fix up there; see
[Limitations](#limitations) for what's still genuinely out of scope.

Composer package (`src/`), unit tested (`tests/`) — see
[Running the tests](#running-the-tests). Use it directly
(`bin/build-organizations`) for an already-flat delimited file, or via
[`process_template.php`](#processing-the-organization_templatexlsx-workbook)/
[`process_template_alt.php`](README_alternate.md) if your data lives in
one of the two Excel templates instead.

If your source data is an Excel workbook rather than a delimited file —
specifically a filled-out copy of the multi-sheet
`Organization_Template.xlsx` — see
[Processing the Organization_Template.xlsx workbook](#processing-the-organization_templatexlsx-workbook)
below instead of hand-flattening it yourself.

---

## Setup

```bash
composer install
```

This resolves `marnold-ebsco/phpfolioclient` from its own GitHub repo
(https://github.com/marnold-ebsco/phpFolioClient, via a `vcs` repository
in [`composer.json`](composer.json) — no local checkout or path needed),
installs Guzzle transitively, and installs
`phpunit/phpunit` as a dev dependency.

## Running it

```bash
php bin/build-organizations --input=orgs.tsv [options]
```

One run builds every record type at once, each to its own file:

| Record type | Built from | Output option | Default filename |
|---|---|---|---|
| Organizations | one per row | `--output=PATH` | stdout |
| Contacts | `contacts[N].*` columns, 0+ per row | `--contacts-output=PATH` | `contacts.json` |
| Interfaces | `interfaces[N].*` columns, 0+ per row | `--interfaces-output=PATH` | `interfaces.json` |
| Interface credentials | that same instance's `interfaces[N].username`/`password`, only if both are present | `--credentials-output=PATH` | `credentials.json` |
| Notes | `notes[N].*` columns, 0+ per row, only for a row whose organization was accepted — see [Notes](#notes) | `--notes-output=PATH` | `notes.json` |
| Categories | names seen in any `categories` field, deduplicated | `--categories-output=PATH` | `categories.json` |
| Organization types | names seen in `organizationTypes`, deduplicated | `--organization-types-output=PATH` | `organization_types.json` |
| Note types | names seen in any `notes[N].type` column, deduplicated | `--note-types-output=PATH` | `note_types.json` |

Contacts/interfaces/credentials/notes/categories/organization-types/
note-types default filenames land next to `--output`'s directory (or
the current directory, if `--output` is stdout). **Every default
filename ends in `.json` regardless of `--format`** — the extension
names the file type, not whether its content is one JSON array or
one-object-per-line; see `--format` below.

| Option | Default | Description |
|---|---|---|
| `--input=PATH` | *(required)* | Delimited input file. |
| `--output=PATH` | stdout | Organizations output file. |
| `--contacts-output=PATH` | `contacts.json` | See table above. |
| `--interfaces-output=PATH` | `interfaces.json` | See table above. |
| `--credentials-output=PATH` | `credentials.json` | See table above. |
| `--notes-output=PATH` | `notes.json` | See table above. |
| `--categories-output=PATH` | `categories.json` | See table above. |
| `--organization-types-output=PATH` | `organization_types.json` | See table above. |
| `--note-types-output=PATH` | `note_types.json` | See table above. |
| `--mapping=PATH` | `organization_field_mapping.json` (project root) | Field-mapping file — see below. |
| `--format=json\|ndjson` | `ndjson` | Applies to all 8 outputs. `ndjson` writes one JSON object per line — the form most loading tools (including a simple line-by-line POST loop) expect; a JSON array isn't directly "loadable" that way. `json` writes a single JSON array instead, if you specifically want that. Either way, filenames still end in `.json` (see above). |
| `--delimiter=CHAR` | `tab` | Field delimiter — **tab is the normal/expected delimiter for input files.** Accepts a literal character or the names `tab`, `pipe`, `semicolon`, `comma`. |
| `--enclosure=CHAR` | `"` | Field quote/enclosure character. |
| `--list-delimiter=STR` | `\|` | Delimiter used *within* a single cell for multi-value fields (e.g. `organizationTypes`). Same convenience names as `--delimiter` are accepted. **Does not apply to `categories`** — a cell listing more than one category name always splits on `;` regardless of this setting (see [Reference data](#reference-data-categories--organization-types)). |
| `--error-log=PATH` | `logs/{input basename}_{timestamp}_{random}.log` (project root, created automatically) | **One log file for the whole run.** Every phase (existing-reference-data lookup, organizations, contacts, interfaces + credentials, notes) writes its own `== section ==` block into it, so everything that went wrong (or, with `--folio-config`, everything that was already found to exist) lives in one place. |
| `--folio-config=PATH` | *(none — offline by default)* | FolioConfig INI file; when given, existing categories/organization/note types are fetched from that tenant first — see [Reference data](#reference-data-categories--organization-types). |
| `--help` | | Print usage. |

Example (the bundled sample file is tab-delimited, so no `--delimiter` is needed):

```bash
php bin/build-organizations --input=organizations_sample.tsv --output=organizations.json
```

If your input file is actually comma-delimited (e.g. a plain `.csv`
export), pass `--delimiter=comma` explicitly — it's no longer the default.

Rows that fail validation (missing required fields, bad enum values,
malformed UUIDs, etc.) are written to the relevant error log and skipped;
every other row (or child record, for contacts/interfaces) is still built
and included in its output. **Every run creates brand-new log files** —
never appended to, so logs from separate runs never collide. stderr only
gets one-line summaries (counts built per record type, plus a single
pointer to the log if anything anywhere was skipped); row-level detail
lives in the log, not the console.

Exit code is `1` if any row of any record type was skipped, `0` otherwise.

The log also brackets the whole run with timing: `Run started ...`
(already there) and a matching `Run ended ...` line right before `Run
complete: ...`, plus their difference — computed via PHP's
`microtime()` — as `Elapsed time: N.NN seconds` (to the hundredth).

With `--folio-config`, both timestamps are shown in **the tenant's own
configured timezone** (the same `localSettings`-style locale
configuration FOLIO's own UI uses to render dates/times), not the
server's — looked up once at startup, before `Run started` is even
written, so every timestamp in the log is consistent. A line right
after `Run started` says which one applied:

```
Run started 2026-08-18T13:58:13-04:00
Times in this log are in the FOLIO tenant's timezone: America/New_York.
```

Without `--folio-config` (or if the tenant has no such setting
configured), timestamps fall back to the server's own default timezone,
named the same way:

```
Run started 2026-08-18T17:56:03+00:00
Times in this log are in this server's default timezone: UTC.
```

### Reference data (categories & organization types)

`categories` (on addresses/phones/emails/urls/aliases/contacts),
`organizationTypes` (on organizations), and a note's `type` (see
[Notes](#notes)) are all real FOLIO resources addressed by UUID — a
legacy file has no way to know those UUIDs ahead of time, so in your
mapped columns you supply the **name** instead (e.g. `Billing`,
`Vendor`, `General`), and the same name always resolves to the same
UUID — not just within one run, but on a completely separate run too,
since that UUID is deterministic (see the paragraph below) — wherever
it appears (see `Organizations\ReferenceRegistry`).

A `categories` cell can list more than one name — `categories` is a real
array on every group that has it, unlike (for example) a URL's `notes`,
which is a single string. Separate multiple category names with a
semicolon (`Billing;Support`), **not** `--list-delimiter` — categories
always split on `;` regardless of that setting, since a category name
itself is free text and more likely to contain the general delimiter's
default `|` than a `;`.

By default every name is treated as new and gets a **deterministic**
UUID (`uuid5` of FOLIO's own well-known namespace and a `tenant:type:name`
string — the same convention FOLIO's own migration tooling uses, the
[`folio_uuid`](https://github.com/FOLIO-FSE/folio_uuid) Python library
— see `ReferenceRegistry::resolve()`), not a random one: the same name
always hashes to the same UUID, run after run, so re-processing the
same legacy data doesn't invent a new UUID for "Billing" every time.
`categories.json`/`organization_types.json`/`note_types.json` list
everything that needs to be created. Pass `--folio-config=PATH` (a
FolioConfig INI file — `okapiUrl`, `tenant_id`, `username`, `password`)
to have it check the target tenant first (`GET /organizations-storage/categories`,
`GET /organizations-storage/organization-types`, and `GET /note-types`)
and reuse a matching name's *real* UUID instead — only names with no
existing match end up in the output files, so you don't recreate a
category/type/note-type that's already there. (The tenant id from
`--folio-config` also becomes part of the hashed string above, so the
same name gets a different UUID in a different tenant, matching the
real `folio_uuid` convention exactly; without `--folio-config`, a fixed
placeholder tenant is used instead — still deterministic, just not
tenant-scoped.) Whatever was found is
written into the run's error log (an `== existing reference data (from
FOLIO) ==` section, naming every matched category/organization
type/note type) — not just a stderr count that scrolls away — so
there's a permanent record of what already existed versus what this
run actually created.

## Running the tests

```bash
php vendor/bin/phpunit
```

154 tests across `tests/`, covering the mapper's resolution rules
(including multi-instance indexing and per-instance sub-mapping), every
cast/validation path, nested-group behavior, the reference-data registry,
the xlsx reader and template flattener, file-reading edge cases, and a
full end-to-end integration test.

---

## Setting up the field mapping

The correspondence between your legacy file's columns and FOLIO
organization fields lives in a JSON file — by default
[`organization_field_mapping.json`](organization_field_mapping.json) —
**not** in code, so you can repoint it at a different legacy system
without touching PHP.

### Format

```json
{
    "data": [
        {
            "folio_field": "name",
            "legacy_field": "Vendor Name",
            "value": "",
            "description": "The name of this organization",
            "fallback_legacy_field": "Company Name"
        }
    ]
}
```

Each entry describes how to resolve **one** FOLIO field, for **each row**,
in this order:

1. If `value` is non-empty, that value is used verbatim (hard-coded) —
   every row gets it, regardless of what's in the input file. Use this for
   fields you want to force to a constant (e.g. always `"status": "Active"`
   for a one-time import of known-active vendors).
2. Otherwise, the column named by `legacy_field` is read from that row, if
   present and non-empty.
3. Otherwise, the column named by `fallback_legacy_field` is read, if
   present and non-empty. Use this when a legacy system stores the same
   piece of data under different column names depending on export batch,
   or when you want a secondary source only for rows missing the primary one.
4. Otherwise the field is left unset for that row.

`legacy_field`/`fallback_legacy_field` values of `""` or `"Not mapped"` are
treated as absent. Column name matching is **case-insensitive**.
`description` is documentation only — it isn't used by the script.

### Editing the mapping

To point at your own legacy export, change `legacy_field` (and optionally
`fallback_legacy_field`) on each entry to match your actual column
headers. You don't need an entry for every field — an omitted or
`"Not mapped"` field is simply never populated. Conversely, you can safely
leave the bundled file's unused entries in place; they only do something
if their column is present and mapped.

To point at a different mapping file entirely, pass `--mapping=PATH`.

### Nested group fields (addresses, phones, emails, urls, aliases, accounts)

`folio_field` for these groups uses dot notation:

```
addresses.addressLine1   addresses.addressLine2   addresses.city
addresses.stateRegion    addresses.zipCode        addresses.country
addresses.language       addresses.categories     (one or more NAMES, ';'-delimited — see Reference data)
addresses.isPrimary

phoneNumbers.phoneNumber (required if the group is present)
phoneNumbers.type        (Office | Mobile | Fax | Other)
phoneNumbers.language    phoneNumbers.categories  phoneNumbers.isPrimary

emails.value (required)  emails.description
emails.language          emails.categories        emails.isPrimary

urls.value (required)    urls.description
urls.language            urls.notes (a single string, not a list)
urls.categories          urls.isPrimary

aliases.value (required) aliases.description

accounts.name (required)        accounts.accountNo (required)
accounts.accountStatus (required)  accounts.description
accounts.appSystemNo            accounts.paymentMethod (Cash | Credit Card | EFT |
                                 Deposit Account | Physical Check | Bank Draft |
                                 Internal Transfer | Other)
accounts.contactInfo            accounts.libraryCode
accounts.libraryEdiCode         accounts.notes
```

(`aliases` and `accounts` have no `isPrimary` — that's a real absence in
the FOLIO schema, not an oversight here.)

#### Multiple instances per organization

Each of these groups can hold more than one entry per organization — e.g.
a billing address *and* a shipping address, or a main phone number *and*
a fax number. `addresses.city` (no bracket) is shorthand for the **first**
instance, `addresses[1].city`; add `addresses[2].city`, `addresses[3].city`,
etc. for additional instances. The number of instances the builder
attempts for a group is exactly however many distinct `[N]`s (plus
the implicit `[1]`) exist in the mapping file for that group — it's *not*
capped, and a row that only populates instance 2 (leaving instance 1
empty) still builds correctly with just one entry.

The bundled [`organization_field_mapping.json`](organization_field_mapping.json)
ships a second instance already wired up for `addresses`, `phoneNumbers`,
`emails`, and `urls` (legacy columns `address2_*`/`phone2_*`/`email2_*`/
`url2_*`), and a second *and third* instance for `aliases` (`alias2_*`,
`alias3_*`) — organizations tend to accumulate more than two former/alternate
names, so it ships with extra headroom there. See
[`organizations_sample.tsv`](organizations_sample.tsv)'s EBSCO row for a
worked example with two of each nested group and three aliases. To add
another instance of any group, copy an existing `[N].*` block for that
group in the mapping file, bump the index to the next number, and point
`legacy_field` at your new columns — no PHP changes needed.

For groups that support it (`addresses`, `phoneNumbers`, `emails`, `urls`):
if no instance is explicitly marked `isPrimary: true`, one is defaulted to
`true` automatically — the **first** instance, unless it's explicitly
marked `false` (not just unmapped), in which case the next instance that
isn't explicitly `false` gets it instead, and so on. If literally every
instance is explicitly marked `false`, that's left as-is — an all-explicit
"no" is respected rather than forced. Map `addresses[2].isPrimary` (etc.)
to a column of your own if you need explicit control (e.g. to mark a
*later* instance as primary, or to guarantee a specific instance is
*not* primary).

Validation errors for a specific instance name it directly, e.g. `Row 5:
'phoneNumbers[2]' group is missing required field 'phoneNumbers[2].phoneNumber'`.

### All other supported `folio_field` names

Top-level scalars: `id`, `name`, `code`, `status`, `description`,
`exportToAccounting`, `language`, `isVendor`, `isDonor`, `sanCode`,
`erpCode`, `paymentMethod`, `accessProvider`, `governmental`, `licensor`,
`materialSupplier`, `taxId`, `liableForVat`, `taxPercentage`,
`claimingInterval`, `discountPercent`, `expectedActivationInterval`,
`expectedInvoiceInterval`, `expectedReceiptInterval`,
`renewalActivationInterval`, `subscriptionInterval`.
`name`, `code`, and `status` are required; `status` must be one of
`Active`/`Inactive`/`Pending`.

Top-level lists (cell value split on `--list-delimiter`): `organizationTypes`
(a list of **names** — see [Reference data](#reference-data-categories--organization-types)),
`acqUnitIds`, `vendorCurrencies`, `contacts`, `privilegedContacts`,
`interfaces`. `acqUnitIds`/`contacts`/`privilegedContacts`/`interfaces`
are lists of literal UUIDs (validated as such) referencing records that
must already exist in FOLIO — they are *not* the same thing as the
`contacts[N].*`/`interfaces[N].*` columns described next, which build
brand-new contact/interface objects rather than referencing existing ones.

### Contacts and interfaces (standalone records, not nested)

Unlike addresses/phones/emails/urls/aliases/accounts, contacts and
interfaces are not *embedded* inside the organization object — they're
FOLIO resources in their own right, so each populated instance becomes
its own record in `contacts.json`/`interfaces.json` rather than a
nested sub-object in the organization's own JSON. (They *are*
automatically referenced by id from the organization's own `contacts`/
`interfaces` arrays — see further down — just not embedded the way
addresses/phones/emails/urls/aliases/accounts are.) The mapping/
indexing mechanics are otherwise identical (`contacts[1].firstName`,
`contacts[2].firstName`, ...).

```
contacts[N].firstName (required)  contacts[N].lastName (required)
contacts[N].prefix                contacts[N].language
contacts[N].notes                 contacts[N].inactive
contacts[N].categories (names)
contacts[N].emails.value          contacts[N].emails.description
contacts[N].phoneNumbers.phoneNumber
contacts[N].addresses.*           contacts[N].urls.*
                                   (same sub-fields as the organization's own groups)

interfaces[N].name         interfaces[N].uri           interfaces[N].notes
interfaces[N].available    interfaces[N].deliveryMethod (Online | FTP | Email | Other)
interfaces[N].type (list — each item one of Admin | End user | Reports | Orders | Invoices | Other)
interfaces[N].statisticsFormat   interfaces[N].locallyStored
interfaces[N].onlineLocation     interfaces[N].statisticsNotes

interfaces[N].username     interfaces[N].password
    (not fields of the interface record itself — see below)
```

A contact's own "DESCRIPTION" column has no home on the contact itself
— it isn't a property of the real `contact` schema (see
`Organizations\Schema\ContactSchema`) — but isn't dropped either: it
maps to `contacts[N].emails.description`, the contact's email's own
description, since that's the one place the real schema does have a
free-text description. (A contact's job title had no home anywhere at
all, and was removed from the template entirely, along with its
"TITLE" column.)
`interfaces[N].type` *is* mapped — the real `interface` schema holds it
as an array of strings (cell value split on `--list-delimiter`, same as
any other list field), each validated against the enum above, not as a
nested object. `interfaces[N].username`/`password`, by contrast, feed a
separate FOLIO resource
(`Organizations\Schema\InterfaceCredentialSchema`, `interfaceId` +
`username` + `password`), not a property of the interface itself, so
when both are present for a given instance, bin/build-organizations
builds a separate credential record alongside that interface — stamped
with that interface's own client-generated `id` as its `interfaceId` —
into `credentials.json`, rather than adding them to the interface object.
An interface with neither column populated simply gets no credential record.

Both ids are **deterministic**, the same `uuid5` scheme described under
[Reference data](#reference-data-categories--organization-types), keyed
by `orgCode:instanceIndex` rather than a name — an interface has no
required field of its own to hash instead, since the real schema
doesn't require even a `name`. A credential hashes under a different
object type than its interface despite sharing the same
`orgCode:instanceIndex`, so the two don't collide with each other.

A contact's id is deterministic too, but prefers a different legacy
identifier when one's available: its own first `emails[N].value`,
lowercased and trimmed, rather than `orgCode:instanceIndex`. A contact
is a *person*, and a person's email is a more natural, row-independent
identity than a position within one row — so the same email always
hashes to the same contact id, **even across different organizations'
rows**, and `contacts.json` only ever contains that contact once
(deduplicated by id), not once per organization that mentions them.
Only a contact with no email mapped falls back to
`orgCode:instanceIndex`, same as an interface.

**Every organization's own `contacts`/`interfaces` arrays are filled in
automatically** with the ids of every contact/interface built from that
same row — merged alongside (not replacing) any literal UUIDs your
mapping already supplies for `contacts`/`privilegedContacts`/
`interfaces` (still the only way to reference a record that already
exists in FOLIO from some *other* import, since those aren't
name/email-addressable the way this package's own same-row records
are). `privilegedContacts` specifically has no such auto-linking:
nothing about a built contact says whether it's "privileged," so that
array still requires a literal UUID if you want to populate it at all.

### Notes

A note is a completely different FOLIO module from everything else in
this document — [mod-notes](https://s3.amazonaws.com/foliodocs/api/mod-notes/s/notes.html),
not mod-organizations-storage. Like contacts/interfaces, each populated
`notes[N]` instance becomes its own record (in `notes.json`), not a
nested part of the organization:

```
notes[N].type (name, required)     notes[N].title (required)
notes[N].content
```

`notes[N].type` is a **name** (e.g. `General`, `Follow-up`), resolved to
a `typeId` UUID the same way `categories`/`organizationTypes` resolve —
see [Reference data](#reference-data-categories--organization-types).
`notes[N].content`, when present, is wrapped in `<p>...</p>` before
being written to `notes.json`, since FOLIO's notes UI renders note
content as HTML, not plain text.

Two fields of the real `note` schema — `domain` and `links` — aren't
mapped from any column at all: `domain` is always the fixed string
`"organizations"` (this package only ever attaches notes to
organizations, never to any other domain the real schema supports), and
`links` is a single `[{id, type}]` entry naming the very organization
the note belongs to, via that organization's own `id`. Since a note is
meaningless without an organization to attach it to, **a note is only
ever built for a row whose organization was accepted** — unlike a
contact or interface, which stand alone regardless of what happens to
their row's organization.

That has a knock-on effect worth calling out explicitly: **every
organization this package builds is given a client-generated,
deterministic `id`** (`uuid5` of the organization's `name`+`code` — the
same convention [Reference data](#reference-data-categories--organization-types)
and interfaces/credentials use, not a random one), whether or not it
ends up with any notes — so `organizations.json` always carries an `id`
field now, not just when notes are involved. FOLIO honors a
client-supplied `id` on create, so a note's `links[].id` can simply
reuse it, exactly the same way `credentials.json` already reuses an
interface's client-generated `id` as its own `interfaceId` (see
[Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)).
Being deterministic rather than random has a useful side effect beyond
notes: re-running the same import reproduces the exact same
organization id every time, and the original and alternate templates'
example data (same organizations, different sheet layout) both hash to
the *identical* id for the *identical* organization — see
[the alternate template's docs](README_alternate.md) for that in practice.

---

## Processing the Organization_Template.xlsx workbook

FOLIO's own bulk-import workbook (`Organization_Template.xlsx`) has a
different shape than the flat delimited files above: one "Main Org
record" row per organization holding just the *primary* address/phone/
email/url/alias, plus one-to-many child sheets (Alt names, Addresses,
Phones, Emails, Contact people, Interfaces, External note, Accounts)
joined back to their organization by "ORG CODE", and a single "Vendor
info" row per organization. [`process_template.php`](process_template.php) reads that
workbook directly and does the job for you:

```bash
php process_template.php --input=Organization_Template_filled.xlsx --output-dir=out/
```

Maintaining or extending the template itself (adding/removing a
column, adding a sheet, changing what's required) is a different job
from filling one out — see [TEMPLATE_README.md](TEMPLATE_README.md)
for that.

### Changes from FOLIO's own blank `Organization_Template.xlsx`

If you're comparing this repo's `Organization_Template.xlsx` against a
copy of FOLIO's own original blank template, here's every sheet/column
difference:

- **Sheets removed:** "Notes" (tied to the `organization` schema
  itself, which has no general-purpose free-text notes field — see
  above).
- **Sheets added:** "URLs" (more than one URL per organization) and
  "External note" (mod-notes notes — see [Notes](#notes)).
- **"Main Org record" gained:** `URL NOTE`, `ORG TYPE`; `URL CATEGORY`
  renamed to `URL CATEGORIES` (it's a real array, not a single value);
  and — so that instance 1 of alias/address/phone/email/url (the one
  that lives on this sheet, not an overflow sheet) can carry the same
  fields instance 2+ can — `ALT NAME DESCRIPTION`, `ADDR LANGUAGE`,
  `ADDR CATEGORIES`, `PHONE TYPE`, `PHONE LANGUAGE`, `PHONE CATEGORIES`,
  `EMAIL DESCRIPTION`, `EMAIL LANGUAGE`, `EMAIL CATEGORIES`, and
  `URL LANGUAGE`.
- **"Addresses"/"Phones"/"Emails" renamed:** `CATEGORY` → `CATEGORIES`
  (same reason). **"Emails" also gained:** `LANGUAGE`.
- **"Contact people" renamed:** `CATEGORY` → `CATEGORIES`; **removed:**
  `TITLE` (a contact's job title has no home in the real `contact`
  schema at all — see [Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)).
- **"Interfaces", "Vendor info", "Accounts", "Alt names": unchanged.**
- Required columns are now marked with a `*` in the header text
  instead of a colored header cell, and a blank row now precedes the
  header row on every sheet except "How to use this workbook" and
  "Main Org record" — see
  [TEMPLATE_README.md](TEMPLATE_README.md#the-required-column-highlighting)
  for both conventions.

It flattens the workbook into the indexed-column row format described
above (`Organizations\TemplateFlattener` — unit tested against a small
fixture in `tests/fixtures/`), writes that to a temporary intermediate
file, then hands off to `bin/build-organizations` to actually build and
validate everything — it doesn't duplicate any building logic itself.
Every `bin/build-organizations` option (`--mapping`, `--format`,
`--folio-config`) passes through unchanged; run
`php process_template.php --help` for the full list, including
`--intermediate=PATH`/`--keep-intermediate` if you want to inspect the
flattened file it generates.

`--error-log` is handled a little specially: this script resolves the
path itself (same default-naming convention as `bin/build-organizations`'s
own, if you don't pass one) and writes its *own* flattening-stage
summary there first, under a `== template flattening ==` heading, before
handing that same path to `bin/build-organizations` (via `--append-log`,
so it continues the file rather than overwriting it). End to end, it's
still one log for the whole run, same as running `bin/build-organizations`
directly.

A contact's job title had no home anywhere in the real `contact`
schema, so its "TITLE" column was removed from the template entirely,
the same way the "Notes" sheet below was — see
[Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)
for what "Contact people"'s remaining "DESCRIPTION" column maps to
instead. The template used to have a plain "Notes" sheet tied
to the `organization` schema itself, removed entirely (rather than
kept-but-dropped) since that schema has no general-purpose free-text
notes field anywhere — the only `notes` property in the whole schema is
`edi.notes`, specific to EDI transmission configuration. The current
**"External note"** sheet is an unrelated, later addition — a real
mod-notes `note`, a completely different FOLIO module — see
[Notes](#notes).

Every enum-constrained column in the template is a real Excel dropdown
(data validation), so a filled-out copy can only contain a value the
code will actually accept: "Main Org record" Vendor (Yes/No) and status
(Active/Inactive/Pending), "Phones" TYPE (Office/Mobile/Fax/Other), and
"Interfaces" TYPE (Admin/End user/Reports/Orders/Invoices/Other) and
DELIVERY METHOD (Online/FTP/Email/Other). The dropdown is a convenience
only — a value typed in that bypasses it is still caught by the same
validation `bin/build-organizations` applies to any other input.

Coloured columns in the template are the ones the real FOLIO schema
actually marks `required` for whatever that column's row/sheet builds —
`ORG CODE`/`ORG NAME`/`ORG status` on "Main Org record" (the
organization's own `name`/`code`/`status`), `ALT NAME` on "Alt names",
`PHONE` on "Phones", `EMAIL` on "Emails", `URL` on "URLs", `FIRST
NAME`/`LAST NAME` on "Contact people" (a contact's *own* required
fields — its embedded `EMAIL`/`PHONE` are not colored, since a contact's
email/phone, like an organization's, is an entirely optional group), and
`ACCOUNT NAME`/`ACCOUNT NUMBER`/`ACCOUNT STATUS` on "Accounts". A
coloured pair can also mean "required *together*, only if you use this
at all" rather than "always required": on "Interfaces", `USERNAME` and
`PASSWORD` are both colored because filling in one without the other
gets that login-credentials record skipped with a validation error, even
though neither is required on its own — and even though neither is
required, `NAME` on that same sheet is deliberately **not** coloured,
because the real `interface` schema has no required fields at all, not
even a name.

Two of the 16 (`RIVERSIDE`, `METRODS`) are **deliberately broken** —
one has an invalid `status` ("Closed"), the other an invalid nested
phone `type` ("Landline") — to demonstrate that a validation failure
anywhere in an organization's row drops that whole organization (logged,
not silently ignored). Every one of the 16 still has at least one email,
whether or not the organization itself builds successfully.

**A rejected organization doesn't take everything else on its row down
with it.** Contacts and interfaces (and, for an interface, its
credential) are separate top-level records — `RIVERSIDE`'s and
`METRODS'` contacts and interfaces still build and appear in
`contacts.json`/`interfaces.json`/`credentials.json` even though
`RIVERSIDE`/`METRODS` themselves are missing from `organizations.json`.
Less obviously, the same is true of **categories and organization
types**: a name referenced by any field on a row — a phone's `CATEGORIES`,
an `ORG TYPE`, etc. — is resolved into `categories.json`/
`organization_types.json` as soon as that field is read, before the
organization's own validation runs, so it's included even if the
organization it came from is ultimately rejected.

The error log calls these out explicitly, right after each affected
file's `Built N ...` line, by file name and position — a `line` number
for `ndjson` (the default; one record per line), or a `record #` for
`json` (a single array, so "line" doesn't mean anything there):

```
Note: contacts.json line 8 is a contact built from row 17, whose organization was rejected above.
Note: interfaces.json line 6 is an interface built from row 17, whose organization was rejected above.
Note: credentials.json line 6 is an interface credential built from row 17, whose organization was rejected above.
```

Categories/organization types are only flagged if *every* row that ever
referenced that name was rejected — a category also used by an accepted
organization (or, since a contact can reference categories too,
by any successfully-built contact) is still needed, and isn't flagged
just because *one* of the rows that happened to mention it was rejected:

```
Note: categories.json line 2 ('OnlyBad') was only referenced by rejected organization row(s) 4 above.
```

This is purely informational, not an error — it doesn't set the exit
code or count toward `hadErrors`.

The original, unmodified `Organization_Template.xlsx` is left in
place — the example-data workbook is a separate copy, so you still have
a blank template to fill out yourself.

---

## Loading the output into FOLIO

[`load_to_folio.php`](load_to_folio.php) is a **separate script** from
`bin/build-organizations`/`process_template.php` — those only ever build
and validate JSON; this one only ever POSTs already-built JSON. It reads
the 8 output files and loads them in the order that respects their UUID
cross-references (the order matters — see the table below for why):

```bash
php load_to_folio.php --folio-config=folio.ini --input-dir=output/ --dry-run   # try this first
php load_to_folio.php --folio-config=folio.ini --input-dir=output/            # then for real
```

`folio.ini` is a FolioConfig file (`okapiUrl`, `tenant_id`, `username`,
`password` — same format `bin/build-organizations --folio-config` uses).
`--input-dir` should point at the directory holding the 8 files (pass
`--categories=PATH` etc. individually if they're not all in one place, or
not named the defaults — note the underscore in `--organization_types`/
`--note_types` specifically, not a hyphen; see `--help`). Run
`php load_to_folio.php --help` for the full option list, including how
to point it at an `--error-log` of its own (same one-file-per-run
convention as `bin/build-organizations`).

| Order | File | Endpoint | Depends on |
|---|---|---|---|
| 1 | `categories.json` | `POST /organizations-storage/categories` | — |
| 2 | `organization_types.json` | `POST /organizations-storage/organization-types` | — |
| 3 | `note_types.json` | `POST /note-types` | — |
| 4 | `organizations.json` | `POST /organizations-storage/organizations` | 1, 2 — an organization's nested addresses/phones/emails/urls `categories` and its own `organizationTypes` are UUIDs pointing at records from steps 1–2 |
| 5 | `notes.json` | `POST /notes` | 3, 4 — a note's `typeId` is a UUID from step 3 (though not literally the same one it was posted with — see below), and its `links[].id` is one of step 4's organizations' own `id` |
| 6 | `contacts.json` | `POST /organizations-storage/contacts` | 1 — a contact's own `categories` field (and its nested groups' `categories`) work the same way |
| 7 | `interfaces.json` | `POST /organizations-storage/interfaces` | — |
| 8 | `credentials.json` | `POST /organizations-storage/interfaces/{interfaceId}/credentials` | 7 — each credential's `interfaceId` must match an interface that already exists; the id in the URL path is that same value |

Concretely: **categories, organization types, and note types first**
(order between those three doesn't matter, but all three before step 4),
**then organizations, then notes** (notes must come after organizations
specifically, since `links[].id` names one), **then contacts** (order
between contacts and organizations/notes doesn't matter — contacts
reference neither; see
[Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)
for why), **then interfaces, then credentials last** (credentials must
come after interfaces specifically, not just somewhere after step 4).
`load_to_folio.php` always runs the 8 files in exactly this order —
there's no option to change it.

A record that fails to load (FOLIO validation error, duplicate,
connectivity blip) is logged and skipped — one bad record doesn't abort
the rest of that file or any later file, matching how
`bin/build-organizations` itself treats a bad row. **This is not
idempotent** — `load_to_folio.php` never checks whether a record already
exists before POSTing — but because every id `bin/build-organizations`
generates is now deterministic (see [Reference data](#reference-data-categories--organization-types)),
re-running against a tenant that already has these exact records fails
those individual POSTs as an id conflict rather than silently creating
duplicates with different ids, the way a random id would have. Still
logged the same way as any other per-record failure, not specially
detected or treated as success.

A few things worth knowing about how it works:
- Every output file is one record per line (`--format=ndjson`, the
  default) or a single JSON array (`--format=json`) — `load_to_folio.php`
  auto-detects which one it's looking at, so either works.
- The `id` already present on every record in `categories.json`,
  `organization_types.json`, `organizations.json`, `interfaces.json`,
  and `credentials.json` (deterministically client-generated by
  `bin/build-organizations` — see [Reference data](#reference-data-categories--organization-types))
  is POSTed as-is — FOLIO accepts a caller-supplied `id` on create for
  these, and sending the *same* one that's already in `credentials.json`'s
  `interfaceId` field (or `notes.json`'s `links[].id`) is exactly what
  keeps those cross-references valid. Don't edit those files to strip
  `id` out before loading.
- **`note_types.json`/`notes.json` are the exception.** FOLIO's
  `/note-types` endpoint always assigns its own id on create — confirmed
  against a live tenant — silently ignoring whatever id is in the
  request body, unlike every other endpoint above. Since `notes.json`'s
  `typeId` values were computed locally and won't match FOLIO's real
  id, `load_to_folio.php` tracks each note type's real id as it's
  created and rewrites every note's `typeId` to match, right before
  posting it (logged as `Remapped typeId ... to ... (FOLIO's real
  note-type id)`).

  mod-notes (both `/note-types` and `/notes`) has also been observed,
  against a live tenant, to return a `500 Internal Server Error` for a
  POST that creates the record anyway. When either POST throws,
  `load_to_folio.php` checks whether the record exists regardless —
  `/note-types` by name, `/notes` by title plus a shared `links[].id`
  (a note has no name to search by) — and, if so, treats it as loaded
  rather than failed (logged as `... POST reported an error (...) but
  already exists in FOLIO ...`); for a note type, its real id is also
  captured for the `typeId` remap above. A record that genuinely fails
  to load (that lookup also comes up empty) is reported as failed as
  usual; for a note type, that also means any note referencing it is
  sent with its original, now-invalid `typeId` and fails too. A
  `--dry-run` can't preview any of this, since it depends on server
  responses that only exist once records are actually POSTed.
- A missing input file (e.g. no `credentials.json` because nothing had
  login credentials, or no `notes.json` because no row had a note)
  isn't an error — that phase is just skipped.
- Every record actually loaded also gets its real, tenant-assigned id
  written to a **cleanup log** (`--cleanup-log`, default a fresh,
  timestamped file under `logs/` next to `--error-log`'s own) — one
  heading per endpoint, one id per line; a record whose real id
  differs from the one sent (note types, and any note built from one)
  gets both, tab-separated, tenant id first. Not written at all in
  `--dry-run`. See [Removing what you loaded](#removing-what-you-loaded)
  for what to do with it.

This is exactly the kind of action — creating records in a shared,
live system — you should be careful with: always `--dry-run` first,
and prefer testing against a sandbox/non-prod tenant before a real one.

## Removing what you loaded

[`cleanup_folio.php`](cleanup_folio.php) reads the cleanup log
`load_to_folio.php` wrote (see above) and deletes everything in it —
useful for clearing out a test load without hunting down every record
by hand. It asks for the two things it needs interactively if you
don't pass them as options:

```bash
php cleanup_folio.php --log=logs/output_cleanup_20260101_120000_abc123.log --folio-config=folio.ini
```

It always shows exactly which endpoints it's about to delete from,
and how many records each, and asks you to confirm before deleting
anything:

```
This will delete the following, from the tenant configured in 'folio.ini':
  /organizations-storage/organizations (20 records)
  /notes (7 records)
  ...

Proceed with deletion? [y/N]:
```

By default it removes everything the log describes, in the reverse of
`load_to_folio.php`'s own load order (credentials, interfaces,
contacts, notes, organizations, note types, organization types,
categories), so nothing is deleted while something else loaded in the
same run still references it. Pass `--endpoints=` (comma-separated,
using the exact heading text from the log, e.g.
`--endpoints=/organizations-storage/organizations,/notes`) to restrict
it to specific endpoints instead — anything not named is left alone.

Reading the id back from the cleanup log rather than the id in
`organizations.json`/etc. directly is what makes this safe for
`note_types.json`/`notes.json`: those files' own ids are the ones this
project computed locally, which the tenant didn't actually use (see
above) — deleting by them would either fail outright or, worse, delete
nothing while looking like it succeeded. `--yes` skips the
confirmation prompt (the endpoint/count summary is still printed
first) for scripting; every deletion attempt, successful or not, is
recorded in its own `--activity-log` (default: a fresh, timestamped
file under `logs/`, named after the cleanup log). Run
`php cleanup_folio.php --help` for the full option list.

This deletes real data from a live tenant with no undo — double-check
the log and the `--folio-config` you're pointing at before confirming,
same caution as loading in the first place.

---

## Limitations

- **`privilegedContacts` has no way to be populated automatically.**
  Nothing in the data says which contacts (if any) are privileged, so
  it still requires a literal UUID for a pre-existing FOLIO record —
  see [Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested).
- **Entirely unmapped:** `agreements`, `edi` (and its nested `ediFtp`/
  `ediJob`), `changelogs`, `tags`, `metadata` on organizations.
- **`acqUnitIds` still requires literal UUIDs**, since it isn't
  name-addressable the way categories/organization/note types are —
  see [Reference data](#reference-data-categories--organization-types).
- **No dedup/upsert.** Each run just builds fresh JSON; besides the
  optional `--folio-config` check for *reference data* specifically, it
  doesn't check FOLIO for an existing organization/contact/interface with
  matching identifying data, and by default doesn't talk to FOLIO at all.
- **POST-shaped only.** There's no support for building a partial-update
  (PATCH) payload for an existing record.
- **The list delimiter isn't escapable.** If a legacy value itself contains
  your `--list-delimiter` character (default `|`), splitting will
  misfire — there's no escape sequence for it.
- **Boolean parsing is a fixed vocabulary:** `true`/`false`/`yes`/`no`/`1`/`0`/`t`/`f`/`y`/`n`
  (case-insensitive). Anything else is a validation error, not a guess.
