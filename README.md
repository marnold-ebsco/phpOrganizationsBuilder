# Organizations Import

Builds FOLIO mod-organizations-storage JSON objects from delimited legacy
data — organizations, contact people, interfaces, categories, and
organization types — per the real FOLIO schemas (see the acq-models repo:
https://github.com/folio-org/acq-models/tree/master/mod-orgs/schemas).

Building and loading are two separate scripts. `bin/build-organizations`
(and `process_template.php`) only build and validate JSON — they never
talk to FOLIO to create anything (with one narrow exception:
`--folio-config`, which *reads* existing categories/organization types
so it doesn't invent duplicates — see
[Reference data](#reference-data-categories--organization-types) below).
[`load_to_folio.php`](load_to_folio.php) is the one that actually POSTs
the resulting files into a live tenant, in the required order — see
[Loading the output into FOLIO](#loading-the-output-into-folio). Wiring
the created contacts'/interfaces' ids back into an organization's own
`contacts`/`interfaces` arrays is still out of scope (see
[Limitations](#limitations)) — that's the one thing neither script does.

There are two implementations here:

| | Entry point | Style |
|---|---|---|
| Procedural | [`build_organizations.php`](build_organizations.php) | Single self-contained script, no setup required. Builds only `organization` objects, exactly one address/phone/email/url/alias each. |
| Class-based | [`bin/build-organizations`](bin/build-organizations) | Composer package (`src/`), unit tested (`tests/`). Builds all 6 record types, multiple instances of each nested group per organization. |

This document covers the **class-based version**. Use it if you want to
reuse the pieces (`RecordBuilder`, `FieldMapper`, etc.) elsewhere, want
test coverage, need more than one address/phone/email/url/alias per
organization, or need anything beyond a bare `organization` object; use
the procedural script only if you want to run something with zero setup
and a single organization object with one of each nested group is enough.

If your source data is an Excel workbook rather than a delimited file —
specifically a filled-out copy of the multi-sheet
`Organization_Template (1).xlsx` — see
[Processing the Organization_Template.xlsx workbook](#processing-the-organization_templatexlsx-workbook)
below instead of hand-flattening it yourself.

---

## Setup

```bash
composer install
```

This resolves `marnold-ebsco/phpfolioclient` from the sibling
`../phpFolioClient2` checkout (via a `path` repository in
[`composer.json`](composer.json) — it's junctioned in, not copied, so it
always tracks that project), installs Guzzle transitively, and installs
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
| Categories | names seen in any `categories` field, deduplicated | `--categories-output=PATH` | `categories.json` |
| Organization types | names seen in `organizationTypes`, deduplicated | `--organization-types-output=PATH` | `organization_types.json` |

Contacts/interfaces/credentials/categories/organization-types default
filenames land next to `--output`'s directory (or the current directory,
if `--output` is stdout). **Every default filename ends in `.json`
regardless of `--format`** — the extension names the file type, not
whether its content is one JSON array or one-object-per-line; see `--format` below.

| Option | Default | Description |
|---|---|---|
| `--input=PATH` | *(required)* | Delimited input file. |
| `--output=PATH` | stdout | Organizations output file. |
| `--contacts-output=PATH` | `contacts.json` | See table above. |
| `--interfaces-output=PATH` | `interfaces.json` | See table above. |
| `--credentials-output=PATH` | `credentials.json` | See table above. |
| `--categories-output=PATH` | `categories.json` | See table above. |
| `--organization-types-output=PATH` | `organization_types.json` | See table above. |
| `--mapping=PATH` | `organization_field_mapping.json` (project root) | Field-mapping file — see below. |
| `--format=json\|ndjson` | `ndjson` | Applies to all 6 outputs. `ndjson` writes one JSON object per line — the form most loading tools (including a simple line-by-line POST loop) expect; a JSON array isn't directly "loadable" that way. `json` writes a single JSON array instead, if you specifically want that. Either way, filenames still end in `.json` (see above). |
| `--delimiter=CHAR` | `tab` | Field delimiter — **tab is the normal/expected delimiter for input files.** Accepts a literal character or the names `tab`, `pipe`, `semicolon`, `comma`. |
| `--enclosure=CHAR` | `"` | Field quote/enclosure character. |
| `--list-delimiter=STR` | `\|` | Delimiter used *within* a single cell for multi-value fields (e.g. `organizationTypes`). Same convenience names as `--delimiter` are accepted. |
| `--error-log=PATH` | `logs/{input basename}_{timestamp}_{random}.log` (project root, created automatically) | **One log file for the whole run.** Every phase (existing-reference-data lookup, organizations, contacts, interfaces + credentials) writes its own `== section ==` block into it, so everything that went wrong (or, with `--folio-config`, everything that was already found to exist) lives in one place. |
| `--folio-config=PATH` | *(none — offline by default)* | FolioConfig INI file; when given, existing categories/organization types are fetched from that tenant first — see [Reference data](#reference-data-categories--organization-types). |
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

`categories` (on addresses/phones/emails/urls/aliases/contacts) and
`organizationTypes` (on organizations) are real FOLIO resources addressed
by UUID — a legacy file has no way to know those UUIDs ahead of time, so
in your mapped columns you supply the **name** instead (e.g. `Billing`,
`Vendor`), and the same name always resolves to the same UUID within one
run, wherever it appears (see `Organizations\ReferenceRegistry`).

By default every name is treated as new and gets a freshly generated
UUID — `categories.json`/`organization_types.json` list everything that
needs to be created. Pass `--folio-config=PATH` (a FolioConfig INI file —
`okapiUrl`, `tenant_id`, `username`, `password`) to have it check the
target tenant first (`GET /organizations-storage/categories` and
`GET /organizations-storage/organization-types`) and reuse a matching
name's *real* UUID instead — only names with no existing match end up in
the output files, so you don't recreate a category/type that's already
there. Whatever was found is written into the run's error log (an
`== existing reference data (from FOLIO) ==` section, naming every
matched category/organization type) — not just a stderr count that
scrolls away — so there's a permanent record of what already existed
versus what this run actually created.

## Running the tests

```bash
php vendor/bin/phpunit
```

122 tests across `tests/`, covering the mapper's resolution rules
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
addresses.language       addresses.categories     (categories is a list of NAMES — see Reference data)
addresses.isPrimary

phoneNumbers.phoneNumber (required if the group is present)
phoneNumbers.type        (Office | Mobile | Fax | Other)
phoneNumbers.language    phoneNumbers.categories  phoneNumbers.isPrimary

emails.value (required)  emails.description
emails.language          emails.categories        emails.isPrimary

urls.value (required)    urls.description
urls.language            urls.notes               urls.categories
urls.isPrimary

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
etc. for additional instances. The number of instances the class-based
builder attempts for a group is exactly however many distinct `[N]`s (plus
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

For groups that support it (`addresses`, `phoneNumbers`, `emails`, `urls`),
if no instance's `isPrimary` is explicitly mapped, the **first** instance
is automatically marked `isPrimary: true` and the rest are left unmarked —
matching the old single-instance behavior. Map `addresses[2].isPrimary`
(etc.) to a column of your own if you need explicit control instead (e.g.
to mark a *later* instance as primary).

Validation errors for a specific instance name it directly, e.g. `Row 5:
'phoneNumbers[2]' group is missing required field 'phoneNumbers[2].phoneNumber'`.

**This is class-based-version-only.** The procedural
[`build_organizations.php`](build_organizations.php) still builds exactly
one instance of each group, full stop — it has no concept of `[N]` indices.

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
interfaces are not embedded inside the organization object — they're
FOLIO resources in their own right, so each populated instance becomes
its own record in `contacts.json`/`interfaces.json` rather than an entry
in the organization's own JSON. The mapping/indexing mechanics are
otherwise identical (`contacts[1].firstName`, `contacts[2].firstName`, ...).

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

A contact's job title has no home in the real `contact` schema (see
`Organizations\Schema\ContactSchema`) and can't be mapped.
`interfaces[N].type` *is* mapped — the real `interface` schema holds it
as an array of strings (cell value split on `--list-delimiter`, same as
any other list field), each validated against the enum above, not as a
nested object. `interfaces[N].username`/`password`, by contrast, feed a
separate FOLIO resource
(`Organizations\Schema\InterfaceCredentialSchema`, `interfaceId` +
`username` + `password`), not a property of the interface itself, so
when both are present for a given instance, bin/build-organizations
builds a separate credential record alongside that interface — stamped
with that interface's own (client-generated) `id` as its `interfaceId` —
into `credentials.json`, rather than adding them to the interface object.
An interface with neither column populated simply gets no credential record.

---

## Processing the Organization_Template.xlsx workbook

FOLIO's own bulk-import workbook (`Organization_Template (1).xlsx`) has a
different shape than the flat delimited files above: one "Main Org
record" row per organization holding just the *primary* address/phone/
email/url/alias, plus one-to-many child sheets (Alt names, Addresses,
Phones, Emails, Contact people, Interfaces, Accounts) joined back to
their organization by "ORG CODE", and a single "Vendor info" row per
organization. [`process_template.php`](process_template.php) reads that
workbook directly and does the job for you:

```bash
php process_template.php --input=Organization_Template_filled.xlsx --output-dir=out/
```

It flattens the workbook into the indexed-column row format described
above (`Organizations\TemplateFlattener` — unit tested against a small
fixture in `tests/fixtures/`), writes that to a temporary intermediate
file, then hands off to `bin/build-organizations` to actually build and
validate everything — it doesn't duplicate any building logic itself.
Every `bin/build-organizations` option (`--mapping`, `--format`,
`--error-log`, `--folio-config`) passes through unchanged; run
`php process_template.php --help` for the full list, including
`--intermediate=PATH`/`--keep-intermediate` if you want to inspect the
flattened file it generates.

Two things the template collects have no destination in the FOLIO
`organization`/`contact` schemas and are read but dropped, not built into
anything: the "Notes" sheet (reported as a count on stderr) and a
contact's job "TITLE" column (silently, since there's nothing to count).

Two gaps in the template itself needed filling to reach full schema
coverage — [`Organization_Template_example_data.xlsx`](Organization_Template_example_data.xlsx)
(16 example organizations, exercising every column at least once; EBSCO
alone has multiple aliases, addresses, phones, emails, URLs, contact
people, notes, and interfaces) shows both:
- The template has no way to record more than one URL per organization —
  it gained a **"URLs" sheet**, mirroring the existing "Emails" sheet's shape.
- The template has no organization-type column at all — "Main Org record"
  gained an **"ORG TYPE" column**, feeding the same name-based
  `organizationTypes` resolution described in [Reference data](#reference-data-categories--organization-types).
- The "Interfaces" sheet had no way to record a delivery method — it
  gained a **"DELIVERY METHOD" column**, mapping to `interfaceN_deliveryMethod`.
- "Main Org record" had no way to describe or categorize its own
  (primary) URL — it gained **"URL DESCRIPTION"** and **"URL CATEGORY"**
  columns, mapping to `url_description`/`url_categories` (the latter a
  category **name**, same resolution as every other `categories` column).

Every enum-constrained column in the template is a real Excel dropdown
(data validation), so a filled-out copy can only contain a value the
code will actually accept: "Main Org record" Vendor (Yes/No) and status
(Active/Inactive/Pending), "Phones" TYPE (Office/Mobile/Fax/Other), and
"Interfaces" TYPE (Admin/End user/Reports/Orders/Invoices/Other) and
DELIVERY METHOD (Online/FTP/Email/Other). The dropdown is a convenience
only — a value typed in that bypasses it is still caught by the same
validation `bin/build-organizations` applies to any other input.

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
types**: a name referenced by any field on a row — a phone's `CATEGORY`,
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

The original, unmodified `Organization_Template (1).xlsx` is left in
place — the example-data workbook is a separate copy, so you still have
a blank template to fill out yourself.

---

## Loading the output into FOLIO

[`load_to_folio.php`](load_to_folio.php) is a **separate script** from
`bin/build-organizations`/`process_template.php` — those only ever build
and validate JSON; this one only ever POSTs already-built JSON. It reads
the 6 output files and loads them in the order that respects their UUID
cross-references (the order matters — see the table below for why):

```bash
php load_to_folio.php --folio-config=folio.ini --input-dir=output/ --dry-run   # try this first
php load_to_folio.php --folio-config=folio.ini --input-dir=output/            # then for real
```

`folio.ini` is a FolioConfig file (`okapiUrl`, `tenant_id`, `username`,
`password` — same format `bin/build-organizations --folio-config` uses).
`--input-dir` should point at the directory holding the 6 files (pass
`--categories=PATH` etc. individually if they're not all in one place, or
not named the defaults). Run `php load_to_folio.php --help` for the full
option list, including how to point it at an `--error-log` of its own
(same one-file-per-run convention as `bin/build-organizations`).

| Order | File | Endpoint | Depends on |
|---|---|---|---|
| 1 | `categories.json` | `POST /organizations-storage/categories` | — |
| 2 | `organization_types.json` | `POST /organizations-storage/organization-types` | — |
| 3 | `organizations.json` | `POST /organizations-storage/organizations` | 1, 2 — an organization's nested addresses/phones/emails/urls `categories` and its own `organizationTypes` are UUIDs pointing at records from steps 1–2 |
| 4 | `contacts.json` | `POST /organizations-storage/contacts` | 1 — a contact's own `categories` field (and its nested groups' `categories`) work the same way |
| 5 | `interfaces.json` | `POST /organizations-storage/interfaces` | — |
| 6 | `credentials.json` | `POST /organizations-storage/interfaces/{interfaceId}/credentials` | 5 — each credential's `interfaceId` must match an interface that already exists; the id in the URL path is that same value |

Concretely: **categories and organization types first** (order between
those two doesn't matter, but both before step 3), **then organizations
and contacts** (order between those two doesn't matter either — neither
references the other; see
[Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)
for why), **then interfaces, then credentials last** (credentials must
come after interfaces specifically, not just somewhere after step 3).
`load_to_folio.php` always runs the 6 files in exactly this order —
there's no option to change it.

A record that fails to load (FOLIO validation error, duplicate,
connectivity blip) is logged and skipped — one bad record doesn't abort
the rest of that file or any later file, matching how
`bin/build-organizations` itself treats a bad row. **This is not
idempotent**: re-running against a tenant that already has these exact
records will generally fail those individual POSTs as duplicates (logged
the same way as any other failure, not specially detected).

A few things worth knowing about how it works:
- Every output file is one record per line (`--format=ndjson`, the
  default) or a single JSON array (`--format=json`) — `load_to_folio.php`
  auto-detects which one it's looking at, so either works.
- The `id` already present on every record in `categories.json`,
  `organization_types.json`, `interfaces.json`, and `credentials.json`
  (client-generated by `bin/build-organizations`) is POSTed as-is — FOLIO
  accepts a caller-supplied `id` on create, and sending the *same* one
  that's already in `credentials.json`'s `interfaceId` field is exactly
  what keeps that cross-reference valid. Don't edit those files to strip
  `id` out before loading.
- `organizations.json` and `contacts.json` records don't reference each
  other, or the contents of `interfaces.json`/`credentials.json` — that
  linkage (an organization's own `contacts`/`interfaces` arrays) isn't
  built by either script (see [Limitations](#limitations)), so there's
  nothing to fix up after loading unless you want to add it yourself.
- A missing input file (e.g. no `credentials.json` because nothing had
  login credentials) isn't an error — that phase is just skipped.

This is exactly the kind of action — creating records in a shared,
live system — you should be careful with: always `--dry-run` first,
and prefer testing against a sandbox/non-prod tenant before a real one.

---

## Limitations

- ~~Only one of each nested group per organization.~~ **Resolved for the
  class-based version** — see
  [Multiple instances per organization](#multiple-instances-per-organization)
  above. The procedural `build_organizations.php` still only builds one
  address/phone/email/url/alias per row; it wasn't changed.
- ~~`contacts`/`privilegedContacts` are UUID references, not people.~~
  **Partially resolved:** contact-person and interface *records* can now
  be built standalone (see
  [Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)),
  and an interface *is* wired to its own credential record (matching
  `interfaceId`s — both get a client-generated `id`) — but none of that
  is linked back into an *organization's* own `contacts`/`interfaces`
  UUID arrays, since that requires the records to already exist in FOLIO
  with known ids, which is a loading-time concern, not a building one.
- **Entirely unmapped:** `agreements`, `edi` (and its nested `ediFtp`/
  `ediJob`), `changelogs`, `tags`, `metadata` on organizations. `accounts`
  *is* now mapped — see
  [Nested group fields](#nested-group-fields-addresses-phones-emails-urls-aliases-accounts)
  — and so, as of the most recent additions, are ~~interface login
  credentials~~ and ~~interface `type`~~ — see
  [Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested).
- ~~No reference-data resolution.~~ **Resolved for categories and
  organization types** — see [Reference data](#reference-data-categories--organization-types).
  `acqUnitIds`, and the `contacts`/`privilegedContacts`/`interfaces`
  *reference* fields (as opposed to the `contacts[N].*`/`interfaces[N].*`
  columns that build new records) still require literal UUIDs, since
  those aren't name-addressable the way categories/organization types are.
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
