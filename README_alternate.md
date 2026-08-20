# Organizations Import — Alternate Template

## TL;DR

```bash
git clone https://github.com/marnold-ebsco/phpOrganizationsBuilder.git
cd phpOrganizationsBuilder
composer install
```

```bash
php process_template_alt.php --input=Organization_Template_Alternate_filled.xlsx --output-dir=output_alt/
```

Then, once you have output you're happy with, load it into a live
FOLIO tenant — always `--dry-run` first:

```bash
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/ --dry-run
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/
```

(`folio.ini` is a FolioConfig file — `okapiUrl`, `tenant_id`, `username`,
`password` — that you create yourself; see [Setup](#setup).)

Loading a test batch you'll want to remove later? `load_to_folio.php`
also writes a cleanup log naming every record's real id, grouped by
endpoint; `cleanup_folio.php` reads it back to delete everything (or
just the endpoints you name) — see
[Removing what you loaded](#removing-what-you-loaded).

---

Builds FOLIO mod-organizations-storage JSON objects — organizations,
contact people, interfaces, categories, and organization types — from
a filled-out copy of `Organization_Template_Alternate.xlsx`, per the
real FOLIO schemas (see the acq-models repo:
https://github.com/folio-org/acq-models/tree/master/mod-orgs/schemas).
It also builds mod-notes `notes` (a completely separate FOLIO module —
see [Notes](#notes)) attached to each organization.

Building and loading are separate scripts.
[`process_template_alt.php`](process_template_alt.php) only builds
and validates JSON — it never talks to FOLIO to create anything (with
one narrow exception: `--folio-config`, which *reads* existing
categories/organization/note types so it doesn't invent duplicates —
see [Reference data](#reference-data-categories--organization-types)
below). [`load_to_folio.php`](load_to_folio.php) is the one that
actually POSTs the resulting files into a live tenant, in the required
order — see [Loading the output into FOLIO](#loading-the-output-into-folio).
An organization's own `contacts`/`interfaces` arrays are already wired
up by the build step, for a contact/interface built from that same row
— see [Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)
— so loading has nothing left to fix up there; see
[Limitations](#limitations) for what's still genuinely out of scope.

Composer package (`src/`), unit tested (`tests/`) — see
[Running the tests](#running-the-tests).

---

## Setup

Clone the repository and install dependencies with Composer:

```bash
git clone https://github.com/marnold-ebsco/phpOrganizationsBuilder.git
cd phpOrganizationsBuilder
composer install
```

Requires PHP 8.1+ with the `zip`, `simplexml`, `curl`, and `mbstring`
extensions enabled (see [`composer.json`](composer.json)) — `composer
install` will say so if one's missing. It resolves
`marnold-ebsco/phpfolioclient` from its own GitHub repo
(https://github.com/marnold-ebsco/phpFolioClient, via a `vcs`
repository already declared in `composer.json` — no separate checkout
or path needed), installs Guzzle transitively, and installs
`phpunit/phpunit` as a dev dependency.

That's everything needed to build JSON offline (see
[Running it](#running-it) below). Actually loading anything into a
live FOLIO tenant — `--folio-config=PATH`, on any script that accepts
it — additionally needs a FolioConfig INI file you create yourself
(`okapiUrl`, `tenant_id`, `username`, `password`). It's never
committed to this repo (see [`.gitignore`](.gitignore)); ask whoever
manages tenant access for those values.

## Running it

[`process_template_alt.php`](process_template_alt.php) reads a
filled-out copy of `Organization_Template_Alternate.xlsx`, flattens
it, and builds every record type it finds:

```bash
php process_template_alt.php --input=Organization_Template_Alternate_filled.xlsx --output-dir=output_alt/
```

Always the same 8 files, at fixed default names, into `--output-dir`
(created for you if it doesn't already exist):

| File | Contains |
|---|---|
| `organizations.json` | one record per accepted row |
| `contacts.json` | "Contact people" sheet rows, 0+ per organization |
| `interfaces.json` | "Interfaces" sheet rows, 0+ per organization |
| `credentials.json` | that same row's username/password, only if both are present |
| `notes.json` | "External note" sheet rows, 0+ per organization, only for an accepted organization — see [Notes](#notes) |
| `categories.json` | every category name referenced anywhere, deduplicated |
| `organization_types.json` | every organization-type name referenced, deduplicated |
| `note_types.json` | every note-type name referenced, deduplicated |

| Option | Default | Description |
|---|---|---|
| `--input=PATH` | *(required)* | The filled-out `Organization_Template_Alternate.xlsx` workbook. |
| `--output-dir=PATH` | current directory | Directory for all 8 output files. |
| `--mapping=PATH` | `organization_field_mapping.json` (project root) | Field-mapping file — see [Setting up the field mapping](#setting-up-the-field-mapping). Only needed if you've customized the mapping (e.g. added a column). |
| `--format=json\|ndjson` | `ndjson` | Applies to all 8 outputs. `ndjson` writes one JSON object per line — the form `load_to_folio.php` (and most other loading tools) expects; a JSON array isn't directly "loadable" that way. `json` writes a single JSON array instead, if you specifically want that. Either way, filenames still end in `.json`. |
| `--error-log=PATH` | `logs/{input basename}_{timestamp}_{random}.log` (project root, created automatically) | **One log file for the whole run** — a `== template flattening ==` summary first, then every build phase's own `== section ==` block. |
| `--folio-config=PATH` | *(none — offline by default)* | FolioConfig INI file; when given, existing categories/organization/note types are fetched from that tenant first instead of being recreated — see [Reference data](#reference-data-categories--organization-types). |
| `--intermediate=PATH` | a temp file, deleted afterward | Where the flattened, delimited intermediate file is written before building from it. |
| `--keep-intermediate` | | Don't delete the intermediate file — useful for seeing exactly what got read from the workbook. |
| `--help` | | Print usage. |

Rows (or child instances, for contacts/interfaces/notes) that fail
validation (missing required fields, bad enum values, malformed UUIDs,
etc.) are written to the error log and skipped; everything else on
that row is still built. **Every run creates brand-new log files** —
never appended to, so logs from separate runs never collide. stderr
only gets one-line summaries (counts built per record type, plus a
single pointer to the log if anything anywhere was skipped); row-level
detail lives in the log, not the console.

Exit code is `1` if anything anywhere was skipped, `0` otherwise.

The log also brackets the whole run with timing: `Run started ...`
and a matching `Run ended ...` line right before `Run complete: ...`,
plus their difference — computed via PHP's `microtime()` — as
`Elapsed time: N.NN seconds` (to the hundredth).

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

## Processing the Organization_Template_Alternate.xlsx workbook

Every sheet is joined back to its organization by "ORG CODE", the
required column on every sheet. "Main Org record" holds only the
fields that can't repeat:

| Column | Maps to |
|---|---|
| `ORG CODE` *(required)* | `code` |
| `ORG NAME` *(required)* | `name` |
| `Vendor (Yes/No)` | `isVendor` |
| `ORG status (Active/Inactive/Pending)` *(required)* | `status` |
| `Description` | `description` |
| `ORG TYPE` | `organizationTypes` (a list of names — see [Reference data](#reference-data-categories--organization-types); pick one from the dropdown, or type your own) |

Every alias, address, phone number, email, and URL for an organization
— **including the first one** — is a row on its own dedicated sheet,
joined by "ORG CODE" and numbered starting at instance 1. There's no
"primary" slot on Main Org record to reserve for any of them:

| Sheet | Columns | Required |
|---|---|---|
| Alt names | `ORG CODE`, `ALT NAME`, `Description` | `ALT NAME` |
| Addresses | `ORG CODE`, `ADDR1`, `ADDR2`, `CITY`, `REGION`, `POSTAL CODE`, `COUNTRY`, `CATEGORIES`, `IS PRIMARY` | — |
| Phones | `ORG CODE`, `PHONE`, `TYPE` (Office/Mobile/Fax/Other), `CATEGORIES`, `IS PRIMARY` | `PHONE` |
| Emails | `ORG CODE`, `EMAIL`, `DESCRIPTION`, `LANGUAGE`, `CATEGORIES`, `IS PRIMARY` | `EMAIL` |
| URLs | `ORG CODE`, `URL`, `DESCRIPTION`, `NOTE`, `CATEGORIES`, `IS PRIMARY` | `URL` |

A fax number is just a normal "Phones" row with `TYPE` set to `Fax` —
not a special second column anywhere. `CATEGORIES` can hold more than
one name, `;`-separated (see [Reference data](#reference-data-categories--organization-types));
`NOTE` on "URLs" is a single free-text string, not a list — it's the
one field a URL has that an email doesn't.

**`IS PRIMARY`** (a Yes/No dropdown) exists on Addresses/Phones/Emails/URLs
because the real schema's `isPrimary` flag on each of those four groups
has to come from somewhere once there's no single implicit "primary"
slot. Leave it blank on every row for an organization and the first row
entered is treated as primary automatically (see
[Multiple instances per organization](#multiple-instances-per-organization)
for the exact default-primary rule, including what happens if you mark
a row explicitly `No`); set it explicitly on any row to control this
yourself.

The remaining sheets:

| Sheet | Columns | Required |
|---|---|---|
| Contact people | `ORG CODE`, `LAST NAME`, `FIRST NAME`, `NOTES`, `EMAIL`, `PHONE`, `DESCRIPTION`, `CATEGORIES` | `LAST NAME`, `FIRST NAME` |
| Interfaces | `ORG CODE`, `NAME`, `TYPE` (list — Admin/End user/Reports/Orders/Invoices/Other), `URL`, `DELIVERY METHOD` (Online/FTP/Email/Other), `USERNAME`, `PASSWORD`, `DESCRIPTION`, `NOTES` | `USERNAME`+`PASSWORD` together, if either is used — see below |
| Vendor info | `ORG CODE`, `PAYMENT METHOD`, `CURRENCIES`, `CLAIMING INTERVAL`, `DISCOUNT %`, `EXPECTED ACTIVATION INTERVAL`, `EXP INVOICE INTERVAL`, `EXP RECEIPT INTERVAL`, `RENEWAL ACTIVATION INTERVAL`, `SUBSCRIPTION INTERVAL`, `EXPORT TO ACCOUNTING (Y/N)`, `TAX ID`, `TAX %`, `LIABLE FOR VAT (Y/N)` | — |
| Accounts | `ORG CODE`, `ACCOUNT NAME`, `ACCOUNT NUMBER`, `DESCRIPTION`, `ACCOUNTING CODE`, `PAYMENT METHOD`, `ACCOUNT STATUS`, `LIBRARY EDI CODE`, `NOTES` | `ACCOUNT NAME`, `ACCOUNT NUMBER`, `ACCOUNT STATUS` |
| External note | `ORG CODE`, `NOTE TYPE`, `NOTE TITLE`, `CONTENTS` | `NOTE TYPE`, `NOTE TITLE` |

A contact's own "DESCRIPTION" column has no home on the contact
itself — it isn't a property of the real `contact` schema — so it
maps to that contact's own email's description instead; see
[Contacts and interfaces](#contacts-and-interfaces-standalone-records-not-nested)
for why, and for what `USERNAME`/`PASSWORD` on "Interfaces" actually
build. "External note" is a real mod-notes `note` — a completely
separate FOLIO module — see [Notes](#notes). Neither this workbook nor
the underlying `organization` schema has a plain, general-purpose
"Notes" sheet: the only `notes` property that schema has at all is
`edi.notes`, specific to EDI transmission configuration.

**Required columns are marked with a `*`** in the header text (on
every sheet, not just Main Org record — the same convention "How to
use this workbook" states up front). A `*` pair can also mean
"required *together*, only if you use this at all" rather than
"always required": `USERNAME`/`PASSWORD` on "Interfaces" are both
marked because filling in one without the other gets that
login-credentials record skipped with a validation error, even though
neither is required on its own — and even though neither is required,
`NAME` on that same sheet is deliberately **not** marked, because the
real `interface` schema has no required fields at all, not even a
name. A blank row precedes the header row on every sheet except "How
to use this workbook" and "Main Org record"; every enum-constrained
column is a real Excel dropdown (data validation), so a filled-out
copy can only contain a value the code will actually accept — typing
in a value that bypasses the dropdown is still caught by the same
validation the build step applies to any other input.

Maintaining or extending this template (adding/removing a column,
adding a sheet, changing what's required) is a different job from
filling one out — see [TEMPLATE_README.md](TEMPLATE_README.md) for
that.

### The bundled example data

[`Organization_Template_Alternate_example_data.xlsx`](Organization_Template_Alternate_example_data.xlsx)
carries 20 organizations covering a realistic range: the first
(`GLOBALTECH`) has a value in every column on every sheet, so it alone
exercises every field this package understands; `GLOBALTECH` plus 4
others (`PRAIRIEBOOKS`, `NORTHSTARLIB`, `HERITAGEPRES`, `SUMMITDATA`)
each have 2+ rows on every repeatable sheet, to exercise multi-instance
handling; the remaining organizations range from a bare
name/code/status to a moderately filled-out vendor. Running it through
`process_template_alt.php` builds 20 organizations, 12 contacts, 13
interfaces, 6 credentials, 7 notes, 6 categories, 6 organization types,
and 4 note types, with every row validating cleanly (no rejected
organizations in this particular dataset — see
[the "rejected organization" behavior](#a-rejected-organization-doesnt-take-everything-else-on-its-row-down-with-it)
below for what happens when one does fail).

The original, unmodified `Organization_Template_Alternate.xlsx` is
left in place — the example-data workbook is a separate copy, so you
still have a blank template to fill out yourself.

### A rejected organization doesn't take everything else on its row down with it

Contacts and interfaces (and, for an interface, its credential) are
separate top-level records — if an organization's own row fails
validation (an invalid `status`, an invalid nested `TYPE`, ...), its
contacts and interfaces still build and appear in
`contacts.json`/`interfaces.json`/`credentials.json`, even though the
organization itself is missing from `organizations.json`. Less
obviously, the same is true of **categories, organization types, and
note types**: a name referenced by any field on a row is resolved into
its own output file as soon as that field is read, before the
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

Categories/organization types/note types are only flagged if *every*
row that ever referenced that name was rejected — a category also used
by an accepted organization (or by any successfully-built contact) is
still needed, and isn't flagged just because *one* of the rows that
happened to mention it was rejected:

```
Note: categories.json line 2 ('OnlyBad') was only referenced by rejected organization row(s) 4 above.
```

This is purely informational, not an error — it doesn't set the exit
code or count toward `hadErrors`.

## Setting up the field mapping

The correspondence between this workbook's flattened columns and FOLIO
organization fields lives in a JSON file — by default
[`organization_field_mapping.json`](organization_field_mapping.json) —
**not** in code.

### Format

```json
{
    "data": [
        {
            "folio_field": "name",
            "legacy_field": "ORG NAME",
            "value": "",
            "description": "The name of this organization",
            "fallback_legacy_field": ""
        }
    ]
}
```

Each entry describes how to resolve **one** FOLIO field, for **each
row**, in this order:

1. If `value` is non-empty, that value is used verbatim (hard-coded) —
   every row gets it, regardless of what's in the sheet. Use this for
   fields you want to force to a constant (e.g. always
   `"status": "Active"` for a one-time import of known-active vendors).
2. Otherwise, the column named by `legacy_field` is read from that row,
   if present and non-empty.
3. Otherwise, the column named by `fallback_legacy_field` is read, if
   present and non-empty.
4. Otherwise the field is left unset for that row.

`legacy_field`/`fallback_legacy_field` values of `""` or `"Not mapped"`
are treated as absent. Column name matching is **case-insensitive**.
`description` is documentation only — it isn't used by the script.

You don't need an entry for every field — an omitted or `"Not mapped"`
field is simply never populated. To point at a different mapping file
entirely, pass `--mapping=PATH`.

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

(`aliases` and `accounts` have no `isPrimary` — that's a real absence
in the FOLIO schema, not an oversight here.)

#### Multiple instances per organization

Each of these groups can hold more than one entry per organization —
on this workbook, that's just another row on the group's own sheet.
`addresses.city` (no bracket) is shorthand for the **first** instance,
`addresses[1].city`; `addresses[2].city`, `addresses[3].city`, etc.
address additional instances. The number of instances the builder
attempts for a group is exactly however many distinct `[N]`s (plus the
implicit `[1]`) exist in the mapping file for that group — currently 5
for addresses/phoneNumbers/emails/urls/aliases, 2 for accounts — it's
*not* otherwise capped, and an organization that only populates
instance 2 (leaving instance 1 empty) still builds correctly with just
one entry.

For groups that support it (`addresses`, `phoneNumbers`, `emails`,
`urls`): if no instance is explicitly marked `isPrimary: true`, one is
defaulted to `true` automatically — the **first** instance, unless
it's explicitly marked `false` (not just unmapped, i.e. `IS PRIMARY`
left blank), in which case the next instance that isn't explicitly
`false` gets it instead, and so on. If literally every instance is
explicitly marked `false`, that's left as-is — an all-explicit "no" is
respected rather than forced.

Validation errors for a specific instance name it directly, e.g. `Row
5: 'phoneNumbers[2]' group is missing required field
'phoneNumbers[2].phoneNumber'`.

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

Top-level lists (cell value split on `--list-delimiter`, default `|`):
`organizationTypes` (a list of **names** — see
[Reference data](#reference-data-categories--organization-types)),
`acqUnitIds`, `vendorCurrencies`, `contacts`, `privilegedContacts`,
`interfaces`. `acqUnitIds`/`contacts`/`privilegedContacts`/`interfaces`
are lists of literal UUIDs (validated as such) referencing records that
must already exist in FOLIO — they are *not* the same thing as the
"Contact people"/"Interfaces" sheet rows described next, which build
brand-new contact/interface objects rather than referencing existing ones.

### Contacts and interfaces (standalone records, not nested)

Unlike addresses/phones/emails/urls/aliases/accounts, contacts and
interfaces are not *embedded* inside the organization object — they're
FOLIO resources in their own right, so each "Contact people"/
"Interfaces" row becomes its own record in
`contacts.json`/`interfaces.json` rather than a nested sub-object in
the organization's own JSON. (They *are* automatically referenced by
id from the organization's own `contacts`/`interfaces` arrays — see
further down — just not embedded the way
addresses/phones/emails/urls/aliases/accounts are.)

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
free-text description. `interfaces[N].type` *is* mapped — the real
`interface` schema holds it as an array of strings (cell value split
on `--list-delimiter`, same as any other list field), each validated
against the enum above, not as a nested object.
`interfaces[N].username`/`password`, by contrast, feed a separate
FOLIO resource (`Organizations\Schema\InterfaceCredentialSchema`,
`interfaceId` + `username` + `password`), not a property of the
interface itself, so when both are present for a given row,
`process_template_alt.php` builds a separate credential record
alongside that interface — stamped with that interface's own
client-generated `id` as its `interfaceId` — into `credentials.json`,
rather than adding them to the interface object. An interface with
neither column populated simply gets no credential record.

Both ids are **deterministic**, the same `uuid5` scheme described
under [Reference data](#reference-data-categories--organization-types),
keyed by `orgCode:instanceIndex` rather than a name — an interface has
no required field of its own to hash instead, since the real schema
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
not mod-organizations-storage. Like contacts/interfaces, each "External
note" row becomes its own record (in `notes.json`), not a nested part
of the organization:

```
notes[N].type (name, required)     notes[N].title (required)
notes[N].content
```

`notes[N].type` is a **name** (e.g. `General`, `Follow-up`), resolved
to a `typeId` UUID the same way `categories`/`organizationTypes`
resolve — see [Reference data](#reference-data-categories--organization-types).
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
same convention used for interfaces/credentials, not a random one),
whether or not it ends up with any notes — so `organizations.json`
always carries an `id` field. FOLIO honors a client-supplied `id` on
create, so a note's `links[].id` can simply reuse it, exactly the same
way `credentials.json` already reuses an interface's client-generated
`id` as its own `interfaceId`. Being deterministic rather than random
has a useful side effect: re-running the same import reproduces the
exact same organization id every time.

## Reference data (categories & organization types)

`categories` (on addresses/phones/emails/urls/aliases/contacts),
`organizationTypes` (on organizations), and a note's `type` (see
[Notes](#notes)) are all real FOLIO resources addressed by UUID —
there's no way to know those UUIDs ahead of time, so in your mapped
columns you supply the **name** instead (e.g. `Billing`, `Vendor`,
`General`), and the same name always resolves to the same UUID — not
just within one run, but on a completely separate run too, since that
UUID is deterministic (see the paragraph below) — wherever it appears
(see `Organizations\ReferenceRegistry`).

A `categories` cell can list more than one name — `categories` is a
real array on every group that has it, unlike (for example) a URL's
`notes`, which is a single string. Separate multiple category names
with a semicolon (`Billing;Support`), **not** `--list-delimiter` —
categories always split on `;` regardless of that setting, since a
category name itself is free text and more likely to contain the
general delimiter's default `|` than a `;`.

By default every name is treated as new and gets a **deterministic**
UUID (`uuid5` of FOLIO's own well-known namespace and a
`tenant:type:name` string — the same convention FOLIO's own migration
tooling uses, the [`folio_uuid`](https://github.com/FOLIO-FSE/folio_uuid)
Python library — see `ReferenceRegistry::resolve()`), not a random
one: the same name always hashes to the same UUID, run after run, so
re-processing the same legacy data doesn't invent a new UUID for
"Billing" every time. `categories.json`/`organization_types.json`/
`note_types.json` list everything that needs to be created. Pass
`--folio-config=PATH` (a FolioConfig INI file) to have it check the
target tenant first (`GET /organizations-storage/categories`, `GET
/organizations-storage/organization-types`, and `GET /note-types`) and
reuse a matching name's *real* UUID instead — only names with no
existing match end up in the output files, so you don't recreate a
category/type/note-type that's already there. (The tenant id from
`--folio-config` also becomes part of the hashed string above, so the
same name gets a different UUID in a different tenant, matching the
real `folio_uuid` convention exactly; without `--folio-config`, a
fixed placeholder tenant is used instead — still deterministic, just
not tenant-scoped.) Whatever was found is written into the run's error
log (an `== existing reference data (from FOLIO) ==` section, naming
every matched category/organization type/note type) — not just a
stderr count that scrolls away — so there's a permanent record of what
already existed versus what this run actually created.

## Loading the output into FOLIO

[`load_to_folio.php`](load_to_folio.php) is a **separate script** from
`process_template_alt.php` — that one only ever builds and validates
JSON; this one only ever POSTs already-built JSON. It reads the 8
output files and loads them in the order that respects their UUID
cross-references:

```bash
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/ --dry-run   # try this first
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/            # then for real
```

`--input-dir` should point at the directory holding the 8 files (pass
`--categories=PATH` etc. individually if they're not all in one place,
or not named the defaults — note the underscore in
`--organization_types`/`--note_types` specifically, not a hyphen; see
`--help`). Run `php load_to_folio.php --help` for the full option list.

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

`load_to_folio.php` always runs the 8 files in exactly this order —
there's no option to change it. A record that fails to load (FOLIO
validation error, duplicate, connectivity blip) is logged and skipped
— one bad record doesn't abort the rest of that file or any later
file. **This is not idempotent** — `load_to_folio.php` never checks
whether a record already exists before POSTing — but because every id
this package generates is deterministic (see
[Reference data](#reference-data-categories--organization-types)),
re-running against a tenant that already has these exact records fails
those individual POSTs as an id conflict rather than silently creating
duplicates with different ids, the way a random id would have.

A few things worth knowing about how it works:
- Every output file is one record per line (`--format=ndjson`, the
  default) or a single JSON array (`--format=json`) — `load_to_folio.php`
  auto-detects which one it's looking at, so either works.
- The `id` already present on every record in `categories.json`,
  `organization_types.json`, `organizations.json`, `interfaces.json`,
  and `credentials.json` (deterministically client-generated — see
  [Reference data](#reference-data-categories--organization-types)) is
  POSTed as-is — FOLIO accepts a caller-supplied `id` on create for
  these, and sending the *same* one that's already in
  `credentials.json`'s `interfaceId` field (or `notes.json`'s
  `links[].id`) is exactly what keeps those cross-references valid.
  Don't edit those files to strip `id` out before loading.
- **`note_types.json`/`notes.json` are the exception.** FOLIO's
  `/note-types` endpoint always assigns its own id on create —
  confirmed against a live tenant — silently ignoring whatever id is
  in the request body, unlike every other endpoint above. Since
  `notes.json`'s `typeId` values were computed locally and won't match
  FOLIO's real id, `load_to_folio.php` tracks each note type's real id
  as it's created and rewrites every note's `typeId` to match, right
  before posting it (logged as `Remapped typeId ... to ... (FOLIO's
  real note-type id)`).

  mod-notes (both `/note-types` and `/notes`) has also been observed,
  against a live tenant, to return a `500 Internal Server Error` for a
  POST that creates the record anyway. When either POST throws,
  `load_to_folio.php` checks whether the record exists regardless —
  `/note-types` by name, `/notes` by title plus a shared `links[].id`
  (a note has no name to search by) — and, if so, treats it as loaded
  rather than failed. A record that genuinely fails to load (that
  lookup also comes up empty) is reported as failed as usual; for a
  note type, that also means any note referencing it is sent with its
  original, now-invalid `typeId` and fails too. A `--dry-run` can't
  preview any of this, since it depends on server responses that only
  exist once records are actually POSTed.
- A missing input file (e.g. no `credentials.json` because nothing had
  login credentials, or no `notes.json` because no row had a note)
  isn't an error — that phase is just skipped.
- Every record actually loaded also gets its real, tenant-assigned id
  written to a **cleanup log** (`--cleanup-log`, default a fresh,
  timestamped file under `logs/` next to `--error-log`'s own) — one
  heading per endpoint, one id per line. A note type's line has both
  ids, tab-separated, tenant id first, since its real id can differ
  from the one sent (FOLIO never honors it — see above). A note's line
  is only ever its one real id — `notes.json` records never carry an
  `id` field in the first place, so there's nothing to compare it
  against. Not written at all in `--dry-run`. See
  [Removing what you loaded](#removing-what-you-loaded) for what to do
  with it.

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
php cleanup_folio.php --log=logs/output_alt_cleanup_20260101_120000_abc123.log --folio-config=folio.ini
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
- **Up to 5 instances per organization** for aliases/addresses/
  phoneNumbers/emails/urls (bounded by how many instances
  `organization_field_mapping.json` defines — see
  [Multiple instances per organization](#multiple-instances-per-organization)
  for how to add more if needed); up to 2 for accounts/contacts/
  interfaces/notes.

## Running the tests

```bash
php vendor/bin/phpunit
```

157 tests across `tests/`, covering the mapper's resolution rules
(including multi-instance indexing and per-instance sub-mapping), every
cast/validation path, nested-group behavior, the reference-data registry,
the xlsx reader and this workbook's own flattener, file-reading edge
cases, and a full end-to-end integration test.
