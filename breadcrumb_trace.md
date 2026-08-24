# Breadcrumb trace: one record through the whole pipeline

This traces a single organization — **GlobalTech Solutions Inc.** (code
`GLOBALTECH`), the first row of the bundled example data,
[`Organization_Template_Alternate_example_data.xlsx`](Organization_Template_Alternate_example_data.xlsx)
— through every layer of the build pipeline, from its raw workbook
cells to the final JSON this package writes. Chosen because it exercises
every branch worth tracing: a `category_ref_list` field (an address's
`categories`), a `ref:organizationType` list, two standalone contact
records, an interface + a matching interface credential, and two mod-notes
notes. All ids below are real output from an actual `--uuid-version=5`
(the default) run — see [README_alternate.md](README_alternate.md) for
the general pipeline shape this trace is walking through step by step.

## 1. The source workbook

`Organization_Template_Alternate_example_data.xlsx` holds this
organization's data spread across several sheets, each with its own
header row (see [`src/AlternateTemplateFlattener.php`](src/AlternateTemplateFlattener.php)'s
own docblock for why the alternate template splits things this way):

| Sheet | Header row | Relevant cells (this organization's row) |
|---|---|---|
| `Main Org record` | 5 | `ORG CODE`=`GLOBALTECH`, `ORG NAME`=`GlobalTech Solutions Inc.`, `ORG TYPE (Choose one or create your own)`=`Vendor\|Publisher` |
| `Addresses` | 2 | `ADDR1`=`100 Innovation Way`, `CITY`=`Austin`, `CATEGORIES`=`Billing;Shipping`, `IS PRIMARY`=`Yes` |
| `Contact people` | 2 | row 1: `FIRST NAME`=`Lan`, `LAST NAME`=`Nguyen`, `NOTES`=`Primary account manager`, `CATEGORIES`=`Sales;Support` |
| `Interfaces` | 2 | row 1: `NAME`=`GT Admin Portal`, `URL`=`https://admin.globaltech.example`, `USERNAME`=`gt_admin`, `PASSWORD`=`s3cur3-pass` |
| `External note` | 2 | row 1: `NOTE TYPE`=`General`, `NOTE TITLE`=`Account overview`, `CONTENTS`=`Long-standing technology vendor; primary contact is Lan Nguyen.` |

Every one of these sheets is grouped by `ORG CODE` and merged into a
single organization row by
[`AlternateTemplateFlattener::flatten()`](src/AlternateTemplateFlattener.php:59)
— see step 2.

## 2. Flattening — legacy field names

[`AlternateTemplateFlattener::flatten()`](src/AlternateTemplateFlattener.php:59)
reads each sheet ([lines 60–70](src/AlternateTemplateFlattener.php:60)),
groups every non-`Main Org record` sheet's rows by lowercased `ORG CODE`
([`groupByOrgCode()`](src/AlternateTemplateFlattener.php:61)), then for
`GLOBALTECH` copies each sheet's cells into a flat row keyed by
**legacy field** name — one `$this->copy()` call per column:

```
code                       <- Main Org record  ORG CODE          ($this->copy, line 81)
name                       <- Main Org record  ORG NAME           (line 82)
organizationTypes          <- Main Org record  ORG TYPE ...       (line 86)
address_addressLine1       <- Addresses        ADDR1              (line 101, inside the loop starting line 99)
address_categories         <- Addresses        CATEGORIES         (line 107)
contact1_firstName         <- Contact people   FIRST NAME         (line 151, inside the loop starting line 150)
contact1_categories        <- Contact people   CATEGORIES         (line 154)
interface1_name            <- Interfaces       NAME               (line 167, inside the loop starting line 166)
interface1_username        <- Interfaces       USERNAME           (line 172)
note1_type                 <- External note    NOTE TYPE          (line 183, inside the loop starting line 182)
note1_title                <- External note    NOTE TITLE         (line 184)
```

The resulting flat row (a small slice of it — the real row has ~100
columns) is one associative array:

```
code=GLOBALTECH  name="GlobalTech Solutions Inc."  organizationTypes="Vendor|Publisher"
address_addressLine1="100 Innovation Way"  address_categories="Billing;Shipping"
contact1_firstName=Lan  contact1_lastName=Nguyen  contact1_categories="Sales;Support"
interface1_name="GT Admin Portal"  interface1_username=gt_admin  interface1_password=s3cur3-pass
note1_type=General  note1_title="Account overview"
```

`process_template_alt.php` writes every flattened row to a temp
tab-delimited file and hands it to `bin/build-organizations` — this
organization lands as **row 2** of that file (row 1 is the header; see
[`DelimitedFileReader::rows()`](src/Io/DelimitedFileReader.php:58), which
yields `rowNum => rowAssoc` starting at 2 for the first data row). Every
error-log line and `ReferenceRegistry::getReferencingRows()` call for
this organization refers back to that same row number, 2.

## 3. Field mapping — legacy field to FOLIO field

[`organization_field_mapping.json`](organization_field_mapping.json)'s
`data` array maps each legacy field to a FOLIO field path; the entries
this organization exercises:

| `legacy_field` | `folio_field` |
|---|---|
| `code` | `code` |
| `name` | `name` |
| `organizationTypes` | `organizationTypes` |
| `address_addressLine1` | `addresses.addressLine1` |
| `address_categories` | `addresses.categories` |
| `contact1_firstName` | `contacts[1].firstName` |
| `contact1_categories` | `contacts[1].categories` |
| `interface1_name` | `interfaces[1].name` |
| `interface1_username` | `interfaces[1].username` |
| `note1_type` | `notes[1].typeId` |
| `note1_title` | `notes[1].title` |

[`FieldMapper::indicesFor('contacts')`](src/Mapping/FieldMapper.php:146)
tells `bin/build-organizations` there's (at least) a `contacts[1]`
instance to build; [`FieldMapper::forInstance('contacts', 1)`](src/Mapping/FieldMapper.php:179)
narrows the mapper to just that instance's fields, and
[`FieldMapper::resolve('firstName', $row)`](src/Mapping/FieldMapper.php:108)
(called by `RecordBuilder` under that narrowed mapper) is what actually
pulls `Lan` out of the row for the `firstName` FOLIO field. The same
three-step dance happens once for `interfaces[1]` and once for
`notes[1]`.

## 4. Schema + RecordBuilder — casting, validating, resolving references

Each schema class ([`src/Schema/*.php`](src/Schema)) declares this
field's type; [`RecordBuilder::build()`](src/RecordBuilder.php) reads
those constants to cast/validate/assemble the record. Three field types
this organization's row hits:

- **A plain scalar** — `code`/`name` are `'string'` in
  [`OrganizationSchema::SCALAR_FIELDS`](src/Schema/OrganizationSchema.php),
  copied through as-is.
- **`ref:organizationType`** — [`OrganizationSchema::LIST_FIELDS`](src/Schema/OrganizationSchema.php:55)
  declares `organizationTypes` this way. `RecordBuilder` splits the cell
  on `|` (the general `--list-delimiter`, default `|`) into `Vendor`
  and `Publisher`, then for each one calls
  [`$this->registry->resolve('organizationType', $name, $rowNum)`](src/RecordBuilder.php:84-85)
  — see step 5.
- **`category_ref_list`** — [`OrganizationSchema::NESTED_GROUPS['addresses']['fields']['categories']`](src/Schema/OrganizationSchema.php:89)
  declares this type. `RecordBuilder` splits on `;` specifically (not
  `--list-delimiter` — see [`RecordBuilder.php:165-166`](src/RecordBuilder.php:165)
  and either README's "Reference data" section for why), giving
  `Billing` and `Shipping`, each resolved the same way as above but
  under the `category` namespace.

## 5. `ReferenceRegistry` — name to UUID, deterministically

[`ReferenceRegistry::resolve()`](src/ReferenceRegistry.php:145) is a
shared, run-wide cache: the first time `resolve('organizationType',
'Vendor', 2)` is called (row 2 — this organization), there's no cached
UUID yet, so (with every namespace left at the default UUID version, 5)
it computes
`uuid5(FOLIO_NAMESPACE, "offline:organizationType:vendor")` — see
[`generateUuidV5()`](src/ReferenceRegistry.php:216) — caches it, and
returns it. Every later reference to `Vendor` (from any other
organization's row) reuses that same cached UUID instead of hashing
again. For this run (no `--folio-config`, so `$tenant` is the fixed
placeholder `'offline'`):

| Namespace | Name | Resolved UUID |
|---|---|---|
| `organizationType` | `Vendor` | `2a1e6c8c-40fb-551a-970b-f43c164c4508` |
| `organizationType` | `Publisher` | `dec5a4f3-c7fc-5219-b631-d97d049db6ba` |
| `category` | `Billing` | `b2b1ecee-f3ce-5f2b-8dee-44e017bbc3b9` |
| `category` | `Shipping` | `c8300dbd-5e23-5b70-9a65-91c6b40930d3` |
| `noteType` | `General` | `794d74b4-72ae-5bb9-b898-696d18a6c31b` |

These same four namespace/name pairs, resolved from *any other*
organization's row that also mentions `Vendor`/`Billing`/`General`,
would return these exact same UUIDs — that's the whole point of routing
every reference through one shared registry instance
(`bin/build-organizations`'s `$registry`, constructed once in `main()`).

## 6. `bin/build-organizations` — assigning this organization's own id, and cross-linking

Once `RecordBuilder` returns this organization's record (name, code,
`organizationTypes` UUIDs, the address with its `categories` UUIDs,
etc.), `main()` calls
[`computeOrganizationId()`](bin/build-organizations:220) as
`computeOrganizationId('offline', 'GlobalTech Solutions Inc.', 'GLOBALTECH', 5)`
([call site](bin/build-organizations:984)) — version `5` (the default
for the `organizations` endpoint, per `--uuid-version`) hashes
`offline:organizations:GlobalTech Solutions Inc.:GLOBALTECH` to:

```
id = ce7f0b65-c4b2-59ee-82bc-aec9713d6aca
```

Separately, [`buildChildRecords()`](bin/build-organizations:544) builds
this same row's `contacts[1]` (Lan Nguyen) and
[`buildInterfacesAndCredentials()`](bin/build-organizations:637) builds
`interfaces[1]` (GT Admin Portal) plus its matching credential — each
gets its own client-generated id the same way, keyed by a legacy id
specific to that record type (a contact prefers its own email as the
legacy id; an interface/credential uses `orgCode:instanceIndex`, i.e.
`GLOBALTECH:1`):

| Record | id | legacy id hashed |
|---|---|---|
| Contact (Lan Nguyen) | `f66c7d10-c2a6-5635-a642-8086cb4399e6` | `lnguyen@globaltech.example` (her own email) |
| Interface (GT Admin Portal) | `93c493e0-7f07-5ac1-b6c3-2d1d2156fdd1` | `GLOBALTECH:1` |
| Interface credential | `bf7aed7f-c906-54a8-9905-38ab5e19aefc` | `GLOBALTECH:1` (different `objectType`, so no collision with the interface's own id) |

Back in `main()`, once contacts/interfaces are built, this
organization's own record is patched
([lines 1069–1076](bin/build-organizations:1069)) to fold those ids into
its `contacts`/`interfaces` arrays — this is why the final
`organizations.json` record below lists
`f66c7d10-...`/`1aae00e9-...` under `contacts` and
`93c493e0-...`/`f0f18208-...` under `interfaces`: they're not columns
mapped from this organization's own row at all, but ids assigned to
*other* records built from the *same* row.

Finally, [`buildNotes()`](bin/build-organizations:750) builds this
row's two `External note` entries. Each note's `links[].id` is set to
this organization's own just-computed id (`ce7f0b65-...`) — a note is
only ever built for a row whose organization was *accepted*, which this
one was:

| Note | id | `typeId` | `links[].id` |
|---|---|---|---|
| "Account overview" | *(server-assigned — see [Testing](CLAUDE.md#testing)/[the "id" quirk](README_alternate.md#loading-the-output-into-folio))* | `794d74b4-...` (`General`) | `ce7f0b65-...` |
| "Renewal timing" | *(server-assigned)* | `7b4658ba-...` (`Follow-up`) | `ce7f0b65-...` |

## 7. Final JSON output

Six of the eight output files end up with a record touched by this
single row. `organizations.json`'s entry for GLOBALTECH (trimmed to the
fields this trace covers):

```json
{
  "name": "GlobalTech Solutions Inc.",
  "code": "GLOBALTECH",
  "organizationTypes": [
    "2a1e6c8c-40fb-551a-970b-f43c164c4508",
    "dec5a4f3-c7fc-5219-b631-d97d049db6ba"
  ],
  "addresses": [
    {
      "addressLine1": "100 Innovation Way",
      "categories": [
        "b2b1ecee-f3ce-5f2b-8dee-44e017bbc3b9",
        "c8300dbd-5e23-5b70-9a65-91c6b40930d3"
      ],
      "isPrimary": true
    }
  ],
  "id": "ce7f0b65-c4b2-59ee-82bc-aec9713d6aca",
  "contacts": [
    "f66c7d10-c2a6-5635-a642-8086cb4399e6",
    "1aae00e9-8e15-5542-bcac-03b551ce1e18"
  ],
  "interfaces": [
    "93c493e0-7f07-5ac1-b6c3-2d1d2156fdd1",
    "f0f18208-c344-5e2c-9811-9f2b15bdf4e0"
  ]
}
```

`contacts.json` (Lan Nguyen's record):

```json
{"firstName":"Lan","lastName":"Nguyen","notes":"Primary account manager","categories":["84379bfe-79b9-5a84-bbb4-f5eaf8320a0c","3e33be81-0f89-55b3-9fde-d01a14381e39"],"id":"f66c7d10-c2a6-5635-a642-8086cb4399e6"}
```

`interfaces.json` + `credentials.json` (GT Admin Portal, `interfaces.json`
trimmed to the fields this trace covers):

```json
{"name":"GT Admin Portal","uri":"https://admin.globaltech.example","deliveryMethod":"Online","id":"93c493e0-7f07-5ac1-b6c3-2d1d2156fdd1"}
{"username":"gt_admin","password":"s3cur3-pass","id":"bf7aed7f-c906-54a8-9905-38ab5e19aefc","interfaceId":"93c493e0-7f07-5ac1-b6c3-2d1d2156fdd1"}
```

`notes.json` ("Account overview" — note its own `links[].id` names the
organization's id computed all the way back in step 6):

```json
{"typeId":"794d74b4-72ae-5bb9-b898-696d18a6c31b","title":"Account overview","content":"<p>Long-standing technology vendor; primary contact is Lan Nguyen.</p>","domain":"organizations","links":[{"id":"ce7f0b65-c4b2-59ee-82bc-aec9713d6aca","type":"organizations"}]}
```

## 8. Where this trace stops

`load_to_folio.php` is the next (and final) hop — it would `POST` each
of these 6 files' records to a live tenant, in dependency order (see
[the loading table](README_alternate.md#loading-the-output-into-folio)),
and record whatever id the tenant actually assigns each note under in a
cleanup log (the one record type here whose `id` isn't client-generated
— see step 6). This trace doesn't go that far: everything above was
produced entirely offline, with no FOLIO tenant involved.
