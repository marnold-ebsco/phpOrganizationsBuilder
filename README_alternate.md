# Alternate Template & Script

## TL;DR

To process data using this template:

```bash
php process_template_alt.php --input="Organization_Template_Alternate_example_data.xlsx" --output-dir=output_alt --error-log=output_alt/run.log  --folio-config=folio.ini
```

Then, once you have output you're happy with, load it into a live
FOLIO tenant — always `--dry-run` first (see
[Loading the output into FOLIO](README.md#loading-the-output-into-folio)
in the main README):

```bash
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/  --dry-run
php load_to_folio.php --folio-config=folio.ini --input-dir=output_alt/
```

(`folio.ini` is a FolioConfig file — `okapiUrl`, `tenant_id`, `username`,
`password`.)

---

A second workbook/script pair, alongside the ones documented in
[README.md](README.md), for teams who'd rather enter *every* address,
phone number, email, URL, and alternative name on its own dedicated
sheet — including the very first one — instead of reserving a "primary"
slot on "Main Org record" for it. Everything else about building,
validating, and loading the resulting JSON is identical to the main
workflow; this document only covers what's different.

## What's different

| | Original | Alternate |
|---|---|---|
| Template | [`Organization_Template.xlsx`](Organization_Template.xlsx) | [`Organization_Template_Alternate.xlsx`](Organization_Template_Alternate.xlsx) |
| Script | [`process_template.php`](process_template.php) | [`process_template_alt.php`](process_template_alt.php) |
| Flattener | `Organizations\TemplateFlattener` | `Organizations\AlternateTemplateFlattener` |

**"Main Org record" holds only fields that can't repeat**: `ORG CODE`,
`ORG NAME`, `Vendor (Yes/No)`, `ORG status (Active/Inactive/Pending)`,
`Description`. There's no `ALT NAME`/`ADDR1`/`PHONE`/`FAX`/`EMAIL`/`URL`
column at all — every alias, address, phone number, email, and URL for
an organization, including what the original template treats as the
"primary" one, is a row on its own dedicated sheet ("Alt names",
"Addresses", "Phones", "Emails", "URLs"), numbered starting at instance
1. A fax number is just a normal "Phones" row with `TYPE` set to `Fax`,
not a special second column on Main Org record.

**"Addresses"/"Phones"/"Emails"/"URLs" each gained an `IS PRIMARY`
column** (Yes/No dropdown), since the real schema's `isPrimary` flag on
each of those four groups has nowhere else to come from once there's no
single implicit "primary" slot on Main Org record. Leave it blank on
every row for an organization and the first row entered is still treated
as primary automatically (same default-primary behavior the original
template relies on — see [Setting up the field mapping](README.md#setting-up-the-field-mapping)
for the exact rule, including what happens if you mark a row explicitly
`No`); set it explicitly on any row to control this yourself.

**"URLs" is a new sheet.** The original blank template has no sheet for
extra URLs at all (only a "Main Org record" URL column) — the alternate
template needs one since URLs no longer have anywhere else to go. It's
shaped like the existing "Emails" sheet, plus a `NOTE` column for the
one field a URL has that an email doesn't: `ORG CODE`, `URL`,
`DESCRIPTION`, `NOTE`, `CATEGORIES`, `IS PRIMARY`. `NOTE` is a single
free-text string (not a list); `CATEGORIES` can hold more than one name,
`;`-separated — see
[Setting up the field mapping](README.md#setting-up-the-field-mapping)
in the main README for both.

Every other sheet — "Contact people", "Interfaces", "External note",
"Vendor info", "Accounts" — is unchanged from the original template and
works exactly as [README.md](README.md) describes, including [Notes](README.md#notes)
for "External note" specifically. Neither template has a plain "Notes"
sheet tied to the `organization` schema itself — see [README.md](README.md#processing-the-organization_templatexlsx-workbook)
for why.

## Why the script needed almost no changes

`AlternateTemplateFlattener` produces the *exact same* flat,
indexed-column row format as `TemplateFlattener`
(`address2_city`, `phoneNumbers[3]`, etc. — see
[Setting up the field mapping](README.md#setting-up-the-field-mapping)).
Concretely, instance 1 of aliases/addresses/phoneNumbers/emails/urls
uses the same bracket-less legacy field names
(`alias_value`, `address_addressLine1`, `phone_phoneNumber`,
`email_value`, `url_value`) that `organization_field_mapping.json`
already used for the original template's Main-Org-record slot — the
alternate flattener just sources them from a sheet row instead. Because
of that, `bin/build-organizations` — the piece that actually builds and
validates every record type — needed **zero changes**. `process_template_alt.php`
is a near copy of `process_template.php` with one line different (which
flattener class it constructs), and hands off to the very same,
unmodified `bin/build-organizations`.

`organization_field_mapping.json` did need extending, but only
additively: entries for instances 4-5 of aliases/addresses/phoneNumbers/
emails/urls (the original only defined up to 2-3, since instance 1 used
to be "free" via Main Org record), and an `isPrimary` entry for *every*
instance of addresses/phoneNumbers/emails/urls (instance 1 never needed
one before, and phoneNumbers never had one at all, since Phones sheet
had no `IS PRIMARY` column until now). Every pre-existing entry is
untouched, so the original template/script combination still works
exactly as before.

## Running it

Same interface as `process_template.php` — see
[Processing the Organization_Template.xlsx workbook](README.md#processing-the-organization_templatexlsx-workbook)
for the full option list, including exactly how `--error-log` is
handled (this script writes its own flattening-stage summary first,
then `bin/build-organizations` appends everything else to that same
file). The rest of the log format — the `Run started`/`Run ended`/
`Elapsed time` lines, the orphan-detection notes, and `--folio-config`
picking up the tenant's own configured timezone for those timestamps —
is [documented under "Running it"](README.md#running-it) in the main
README; since it's all written by the same, unmodified
`bin/build-organizations`, it's identical here.

```bash
php process_template_alt.php --input=Organization_Template_Alternate_filled.xlsx --output-dir=out/
```

[`Organization_Template_Alternate_example_data.xlsx`](Organization_Template_Alternate_example_data.xlsx)
carries the *same* 16 organizations as
[`Organization_Template_example_data.xlsx`](README.md#processing-the-organization_templatexlsx-workbook)
— it's a direct port: every field that used to sit on Main Org record
(the "primary" alias/address/phone/email/url, plus a `FAX` value) became
a row on its dedicated sheet instead, marked `IS PRIMARY = Yes`, with
`FAX` becoming a normal Phones row with `TYPE = Fax`; every pre-existing
extra row on Alt names/Addresses/Phones/Emails/URLs (the second address,
third phone, etc.) is kept as-is; the same 5 sample notes on "External
note" (EBSCO gets two) are carried over unchanged, since that sheet is
identical between the two templates. Running both templates' example
data through their respective scripts produces field-for-field
identical `organizations.json`/`notes.json`/`categories.json`/
`organization_types.json`/`note_types.json`/`interfaces.json`/
`credentials.json` output — **including every id**, not just the other
fields: every id this package generates is deterministic (see
[Reference data](README.md#reference-data-categories--organization-types)
in the main README), so the same organization/category/interface hashes
to the identical UUID regardless of which template built it. A useful
sanity check if you're verifying a change to either flattener — any
difference at all (id or otherwise) between the two runs' output is a bug.

Required-column highlighting (the same "Coloured columns are mandatory"
convention from the original template) is applied consistently: `ORG
CODE` on every sheet, plus whatever the real FOLIO schema actually marks
`required` for that record type (`ALT NAME`, `PHONE`, `EMAIL`, `URL`,
`FIRST NAME`/`LAST NAME`, `ACCOUNT NAME`/`ACCOUNT NUMBER`/`ACCOUNT
STATUS`, `NOTE TYPE`/`NOTE TITLE`). `IS PRIMARY` itself is never
required — it's optional on every row, per the real schema.

Two of the 16 (`RIVERSIDE`, `METRODS`) are still deliberately broken the
same way as the original — one has an invalid `status`, the other an
invalid `TYPE` on one of its Phones rows — and the same rule applies:
**a rejected organization doesn't take everything else on its row down
with it.** Contacts and interfaces (and, for an interface, its
credential) are separate top-level records, and any category/
organization-type name referenced anywhere on the row is resolved before
the organization's own validation runs — see the note in
[README.md](README.md#processing-the-organization_templatexlsx-workbook)
for the full explanation, which applies here identically.

The output — `organizations.json`, `contacts.json`, `interfaces.json`,
`credentials.json`, `categories.json`, `organization_types.json` — is
identical in shape to the original workflow's output, and loads into
FOLIO the same way; see
[Loading the output into FOLIO](README.md#loading-the-output-into-folio)
in the main README.

## Limitations

Same as the original template/script (see
[Limitations](README.md#limitations) in the main README), plus:

- **Up to 5 instances per organization** for aliases/addresses/
  phoneNumbers/emails/urls (bounded by how many instances
  `organization_field_mapping.json` defines — see
  [Setting up the field mapping](README.md#setting-up-the-field-mapping)
  for how to add more if needed). The original template's effective cap
  was lower for these same groups (2-3, since one instance came free via
  Main Org record), so this is strictly more headroom, not less.
