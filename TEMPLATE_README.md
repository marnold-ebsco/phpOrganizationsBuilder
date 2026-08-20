# Maintaining the organization templates

This is a guide for keeping `Organization_Template.xlsx` /
`Organization_Template_Alternate.xlsx` (and their `_example_data`
copies) in sync with the code that reads them. It's for whoever edits
the templates later — adding a column, adding a sheet, or changing
what's required — so the workbook and the code don't quietly drift
apart.

If you just need to *use* the templates, see [README.md](README.md) /
[README_alternate.md](README_alternate.md) instead. This file is about
changing them.

## The pipeline, in one paragraph

A filled-out workbook goes through four layers before it becomes JSON,
and each layer only knows about the one next to it — nothing
cross-checks all four for you:

1. **The xlsx file itself** — sheet names, header row, column names,
   and which header cells are colored (see
   [The "required" column highlighting](#the-required-column-highlighting)).
2. **A flattener class** — `src/TemplateFlattener.php` for the
   original workbook, `src/AlternateTemplateFlattener.php` for the
   alternate one — reads specific sheet names and column headers (by
   exact string, case-sensitive for sheet names, trimmed for headers)
   and writes each one into a flat, one-row-per-organization array
   under its own **legacy field** key (`address_city`,
   `contact2_firstName`, `note1_type`, ...). This is the *only* place
   that knows the xlsx file's literal sheet/column names.
3. **`organization_field_mapping.json`** — maps each **legacy field**
   key from step 2 to a **FOLIO field** path (`addresses.city`,
   `contacts[2].firstName`, `notes[1].typeId`, ...) that a schema class
   understands. This is the *only* place that connects a flattener's
   output to a schema's input — see
   [`src/Mapping/FieldMapper.php`](src/Mapping/FieldMapper.php).
4. **A schema class under `src/Schema/`** — declares that a FOLIO
   field exists at all, its cast type, whether it's required, and any
   enum/pattern/reference-lookup rules. `src/RecordBuilder.php` reads
   these constants to actually build and validate each record.

Nothing enforces that these four layers agree with each other. A typo
in a legacy-field key, or a schema field the mapping file doesn't
mention, just silently produces a record missing that field (or, for a
required field, a validation error at build time) — there's no
"unknown column" or "unmapped field" warning. The recipes below exist
to keep all four in sync by hand.

Both templates funnel into the exact same mapping file and schema
classes — see [Keeping both templates in sync](#keeping-both-templates-in-sync) —
so a schema-level change (recipe 1b, 3, or 4-instances) always affects
both; a sheet-layout change (column moved, sheet renamed) usually only
touches one flattener.

## Recipe: add a column to an existing sheet

**Case A — the field already exists in a schema class, just isn't
mapped from this sheet yet** (e.g. `interface.statisticsFormat` exists
on `InterfaceSchema` but nothing maps to it).

1. Add the column to the sheet in both `Organization_Template.xlsx`
   and `Organization_Template_Alternate.xlsx` (and both
   `_example_data` copies, with a realistic example value). Pick a
   header name; it doesn't need to match the FOLIO field name.
2. In the matching flattener method (`TemplateFlattener::flatten()` /
   `AlternateTemplateFlattener::flatten()`), add a
   `$this->copy($flat, $row, 'YOUR HEADER', 'legacy_field_name');`
   line inside the right sheet's loop. Pick a `legacy_field_name` that
   doesn't collide with an existing one.
3. In `organization_field_mapping.json`, add an entry:
   ```json
   {
       "folio_field": "interfaces[1].statisticsFormat",
       "legacy_field": "interface1_statisticsFormat",
       "value": "",
       "description": "..."
   }
   ```
   `folio_field` must be the schema's own dotted path (bracket the
   instance number for anything inside `NESTED_GROUPS`, or for a
   top-level multi-instance record like `contacts[N]`/`notes[N]`);
   `legacy_field` must exactly match the key you just added in step 2
   (matching is case-insensitive, but keep them identical to avoid
   confusion).
4. Re-run `vendor/bin/phpunit` and add a test asserting the new column
   flows through — see `testAccountsSheetMapsToAccountOne()` in
   `tests/TemplateFlattenerTest.php` for the flattener side, and
   `ChildRecordBuildingTest.php` if the field belongs to a top-level
   record type (contacts/interfaces/notes) rather than the
   organization itself.

**Case B — the field doesn't exist in any schema class yet.** Do this
only after confirming the field is real in FOLIO's own schema (see the
`@see` links at the top of each `src/Schema/*.php` file for the
upstream schema doc/JSON this package tracks).

1. Add the field to the appropriate schema class's `SCALAR_FIELDS`
   (scalar), `LIST_FIELDS` (multi-value cell), or the right
   `NESTED_GROUPS[...]['fields']` entry (part of an
   address/phone/email/url/alias/account instance) — see
   `src/Schema/OrganizationSchema.php` for the fullest example of all
   three shapes, and the class docblocks on `RecordBuilder` for exactly
   what each cast type/`ref:` prefix means.
2. If the field is required, add it to that schema's
   `REQUIRED_FIELDS` (top-level) or the nested group's `'required'`
   array. If it's an enum, add it to `TOP_LEVEL_ENUMS`/
   `LIST_FIELD_ENUMS`/the group's `'enums'`. If it must match a
   pattern (like a URL), add it to `TOP_LEVEL_PATTERNS`/the group's
   `'pattern'`.
3. Then follow Case A's steps 1–4 above to wire a column to it.
4. If you marked it required, also color its header cell in both
   templates — see
   [The "required" column highlighting](#the-required-column-highlighting) —
   and add/extend a validation test (e.g.
   `testInterfaceCredentialMissingPasswordIsReported()` in
   `tests/ChildRecordBuildingTest.php`) confirming a missing value is
   actually rejected.

## Recipe: remove a column

1. Remove the `$this->copy(...)` line from both flatteners (only the
   one(s) that actually have that column — the two templates aren't
   always structured the same way).
2. Remove the matching entry (or entries — a field mapped from more
   than one instance, e.g. `addresses[1]`..`[5]`) from
   `organization_field_mapping.json`.
3. Remove the column from both xlsx templates and both `_example_data`
   copies.
4. **Don't** remove the field from its schema class unless the field
   itself is being fully retired everywhere (every template, every
   test, every mapping entry) — a schema field can legitimately have
   no column mapped to it at all (nothing requires 1:1 coverage), so
   leaving it in the schema costs nothing and keeps the option open to
   map it again later. Only remove it from the schema if you've
   confirmed it's genuinely unused (grep the mapping file and both
   flatteners for it first).
5. Update or remove any test that referenced the column (search
   `tests/` for the header name and the legacy-field name).

## Recipe: add a new sheet

There are two genuinely different cases here — figure out which one
you're in before touching anything.

**Case A — a new instance of an existing repeatable group that
deserves its own sheet** (this already happened once: the alternate
template's Addresses/Phones/Emails/URLs sheets are exactly this,
splitting what the original template crams onto Main Org record).
Follow [Recipe: add a column to an existing sheet](#recipe-add-a-column-to-an-existing-sheet)
once per column, reading from your new sheet instead of an existing
one, and see `AlternateTemplateFlattener::readSheetRows()`/
`groupByOrgCode()` for the join-by-ORG-CODE pattern every child sheet
in this codebase uses — reuse it rather than inventing something new.

**Case B — an entirely new, standalone FOLIO record type**, the way
`External note`/mod-notes (`src/Schema/NoteSchema.php`) was added this
way earlier. This is the bigger lift. In order:

1. **Confirm the real FOLIO schema first.** Find its `.json`/`.yaml`
   schema (acq-models on GitHub for mod-organizations-storage records;
   the module's own repo for anything else, like mod-notes was) —
   don't guess at field names or requiredness.
2. **Add a new `src/Schema/<Name>Schema.php`** class, same shape as
   the existing ones (`SCALAR_FIELDS`, `LIST_FIELDS`, `NESTED_GROUPS`,
   `REQUIRED_FIELDS`, `TOP_LEVEL_ENUMS`, `TOP_LEVEL_PATTERNS`,
   `LIST_FIELD_ENUMS`). If any field is assigned programmatically
   rather than mapped from a column (like a note's `domain`/`links`,
   or a credential's `interfaceId`), leave it *out* of
   `SCALAR_FIELDS`/`REQUIRED_FIELDS` entirely and document why in the
   class docblock — see `NoteSchema`'s docblock for exactly this
   reasoning (a hardcoded hidden field breaks the
   "instance doesn't apply to this row" empty-check every multi-instance
   group depends on).
3. **Add the sheet** to both xlsx templates (and both `_example_data`
   copies), with an `ORG CODE` column (the universal join key every
   child sheet uses) plus one column per mapped field.
4. **Add flattening logic** to both `TemplateFlattener::flatten()` and
   `AlternateTemplateFlattener::flatten()` — a `groupByOrgCode(...)`
   call plus a `foreach` loop numbering instances from 1, mirroring
   `External note`'s block in either file exactly (both must produce
   the *identical* legacy-field key scheme, e.g. `note{index}_type`,
   since they share one mapping file).
5. **Add mapping entries** for each instance you want to support (see
   [Recipe: add more instances to a repeatable group](#recipe-add-more-instances-to-a-repeatable-group)
   for how many is reasonable to start with).
6. **If records of this type get built as independent top-level
   records** (not nested inside the organization) — like contacts,
   interfaces, and notes are, as opposed to accounts/addresses/etc.
   which nest inside the organization object itself — add a
   `build<Name>()`-shaped function to `bin/build-organizations`,
   modeled on `buildChildRecords()` (simplest — see notes'
   `buildNotes()` for the extra "only if the organization was
   accepted" + `ref:` handling it layers on) or
   `buildInterfacesAndCredentials()` (if it also produces a companion
   record). Wire its output into `main()`: an output-path option/
   default filename, a call to the new build function, and a
   `writeRecords(...)` call.
7. **If this record type must reference something not yet buildable
   when the record itself is built** (like a note's `links[].id`
   needing the organization's own id, or a credential's `interfaceId`
   needing its interface's id) — assign that field *after*
   `RecordBuilder::build()` returns, in `bin/build-organizations`
   itself, not in the schema. See `buildNotes()`'s
   `$note['links'] = [...]` line for the pattern.
8. **If this is a genuinely separate FOLIO module** (its own base URL,
   like mod-notes vs. mod-organizations-storage) — add a phase to
   `load_to_folio.php`'s `PHASES` constant, in dependency order (does
   it need to load before or after organizations? see the existing
   comment above `PHASES` for why order matters), and update
   README.md's "Loading the output into FOLIO" section, including its
   dependency table.
9. **Write tests.** At minimum: a `RecordBuilder`-level test (modeled
   on `ChildRecordBuildingTest.php`'s notes tests —
   `testBuildsTwoNotesFromOneRow()`, the "unused instance builds
   empty, not a false required-field error" regression test, and a
   `ref:`-resolution test if applicable) and a flattener-level test in
   both `TemplateFlattenerTest.php` and
   `AlternateTemplateFlattenerTest.php`.
10. **Document it** — add a section to both README.md and
    README_alternate.md (or a shared one linked from both, if the
    behavior is identical), following the existing "### Notes"
    section's shape as a model.

## Recipe: add more instances to a repeatable group

Every repeatable group's *ceiling* is just how many instances
`organization_field_mapping.json` happens to define —
`FieldMapper::indicesFor($schemaKey)` only builds instances the
mapping file actually has entries for (currently: addresses/aliases/
emails/phoneNumbers/urls go up to 5; accounts/contacts/interfaces/notes
go up to 2 — check `organization_field_mapping.json` directly, since
this changes over time). Adding a 3rd contact, a 6th address, etc.
needs no schema or flattener change at all — only:

1. **Add the mapping entries** for the new instance number, one per
   field the group already supports for its other instances — copy an
   existing instance's block and bump every `[N]`/`{index}` in both
   `folio_field` and `legacy_field`.
2. **Add the matching sheet row/column headroom.** For a Main-Org-record
   slot-based field on the *original* template (address/phone/email/
   url/alias instance 1), there's no more room to add — those already
   live on their own overflow sheet starting at instance 2, so this
   only matters for the alternate template or for contacts/interfaces/
   notes/accounts, which are already one-row-per-instance on their own
   sheet with no ceiling in the xlsx itself. Nothing to change there —
   a spreadsheet user just adds another row.
3. **Bump the flattener's `$index` loop** only if you *hardcoded* a
   cap somewhere (this codebase doesn't — every flattener loop numbers
   instances by iterating however many rows exist for that org code, so
   this step is usually a no-op; double check the specific `foreach`
   block for the group you're extending).
4. Add a test confirming the new instance number actually resolves
   (see `testBuildsTwoContactsFromOneRow()` for the 2-instance case;
   extend it or add a 3rd-instance variant).

## Keeping both templates in sync

`Organization_Template.xlsx` and `Organization_Template_Alternate.xlsx`
have genuinely different sheet layouts (see README.md's "Changes from
FOLIO's own blank Organization_Template.xlsx" section for the exact
differences), but they share:

- the **same** `organization_field_mapping.json`
- the **same** schema classes
- the **same** `bin/build-organizations`

This only works because both flatteners are written to produce
*identical* legacy-field keys for the same logical data, even though
they read it from different sheets/columns. Whenever you change one
flattener, ask whether the other template has (or should have) the
equivalent sheet/column, and whether its flattener needs the matching
change — the two flatteners are meant to drift in *xlsx layout* only,
never in *output key naming*. When in behavior doubt, diff the two
flattener files' relevant block side by side; they're deliberately
structured to look almost identical except for which sheet/column each
reads from.

If a change is genuinely one-template-only (e.g. the alternate
template's per-instance `IS PRIMARY` column, which the original
template doesn't need since only the alternate's Main-Org-record-free
layout has no other way to express it), that's fine — just make sure
the *legacy field* it produces (`address2_isPrimary`) is one the
mapping file/schema already understands, not a new ad hoc name.

## The "required" column highlighting

Both templates mark a column as required, but by two different
conventions now:

- **`Organization_Template.xlsx`** colors the header cell —
  "Coloured columns are mandatory" is stated directly on its "How to
  use this workbook" sheet.
- **`Organization_Template_Alternate.xlsx`** appends a red `*` to the
  header text instead — "Columns marked with * are mandatory" is
  stated on its own "How to use this workbook" sheet and on "Main Org
  record". `AlternateTemplateFlattener::readSheetRows()` strips a
  trailing `*` (and re-trims) before using a header as a key, so every
  `$this->copy(...)` call elsewhere in that class keeps using a
  column's plain name (`'ORG CODE'`, `'PHONE'`, ...) regardless of
  whether it's currently marked required — **only that one method
  needs to know the marker exists at all.**

Either way, this is a **purely manual, visual/textual convention**;
nothing in the code enforces that a colored/starred column actually
matches a schema's `REQUIRED_FIELDS`. If you add or change a required
field, you must mark (or unmark) the header yourself, using whichever
convention that template uses, or the template will silently lie
about what's actually required.

The convention, based on the current templates:

- `ORG CODE` is marked required on **every** sheet — it's the join key
  every flattener needs to associate a child-sheet row with its
  organization, even though `code` is only literally in
  `OrganizationSchema::REQUIRED_FIELDS` once.
- On "Main Org record": columns matching `OrganizationSchema::REQUIRED_FIELDS`
  (`ORG NAME`, `ORG status`) are marked.
- On a nested-group sheet (Addresses/Phones/Emails/URLs/Accounts):
  columns matching that group's own `'required'` array in
  `OrganizationSchema::NESTED_GROUPS` are marked (e.g. `PHONE` on
  Phones, since `phoneNumbers`'s required array is `['phoneNumber']`;
  nothing else on Addresses, since `addresses`'s required array is
  empty).
- On "Contact people": columns matching `ContactSchema::REQUIRED_FIELDS`
  (`FIRST NAME`, `LAST NAME`) are marked.
- On "Interfaces": `USERNAME`/`PASSWORD` are marked even though
  `InterfaceSchema::REQUIRED_FIELDS` is empty — they're required by the
  *companion* `InterfaceCredentialSchema` record those two columns
  build together, not by the interface itself. If you fill in one but
  not the other, that row's credential is rejected (logged, not
  fatal); the interface itself still loads fine.
- On "External note": `NOTE TYPE`/`NOTE TITLE` match
  `NoteSchema::REQUIRED_FIELDS`.

When adding a required field anywhere, mark its header to match one of
these existing patterns; when removing a `REQUIRED_FIELDS`/
`'required'` entry, remove the marking too.

## After any change: verify

1. `vendor/bin/phpunit` — should stay at 100% passing. Add tests for
   whatever you changed rather than treating a green run alone as
   proof (a missing test won't fail).
2. Run the actual template through the pipeline end to end:
   ```bash
   php process_template.php --input=Organization_Template_example_data.xlsx --output-dir=/tmp/verify --keep-intermediate
   ```
   (`php process_template_alt.php` for the alternate one) and read the
   resulting `logs/*.log` file — a field that silently didn't map
   shows up as a missing value in the output JSON, not as an error, so
   also spot-check the actual output JSON for the field you just added.
3. `--dry-run` `load_to_folio.php` against the output if the change
   touches anything that gets POSTed to FOLIO, to catch a schema
   mismatch before it reaches a live tenant.

## Common pitfalls

- **A flattener's own header matching is case-*sensitive* and exact**
  (only whitespace, and — for the alternate template only — a trailing
  `*`, are stripped first). This is a different, stricter rule than
  `FieldMapper`'s `folio_field`/`legacy_field` matching one layer over
  (see [The pipeline](#the-pipeline-in-one-paragraph)), which *is*
  case-insensitive. Renaming a header's wording at all — even just
  adding a parenthetical like `ORG TYPE` → `ORG TYPE (Choose one or
  create your own)`, or `EXP ACTIVATION INTERVAL` → `EXPECTED
  ACTIVATION INTERVAL` — breaks the matching `$this->copy(...)` call
  exactly as if the column had been deleted: no error, the field just
  stops populating. Whenever you reword a header, grep the matching
  flattener for the *old* text and update it in the same change (see
  [Recipe: add a column](#recipe-add-a-column-to-an-existing-sheet)) —
  don't assume a cosmetic wording change is free.
- **A trailing space kept in a header** (a few existing headers, like
  `'ADDR1 '`, do have one) is fine — `readSheetRows()` trims it before
  using the header as a key — but the flattener's `$this->copy(...)`
  call itself must still name the header exactly as it's spelled
  otherwise, or nothing will match.
- **A `folio_field` for a nested-group instance needs the bracket** —
  `addresses.city` (no bracket) is shorthand for `addresses[1].city`
  only; a 2nd+ instance must be written explicitly as `addresses[2].city`.
- **`legacy_field`/`fallback_legacy_field` of `""` or `"Not mapped"`
  are treated as absent**, not as literal column names — don't use
  either string as an actual header name.
- **Categories use `;` as their in-cell delimiter, not `|`** — every
  other multi-value field uses `|` (or whatever `--list-delimiter`
  is set to) — see `RecordBuilder::CATEGORY_DELIMITER`. Don't assume
  one delimiter convention applies everywhere.
- **A hardcoded mapping-file `"value"` resolves unconditionally** —
  fine for a genuinely constant field, but never use it for a field
  that's supposed to distinguish "this instance wasn't used" from
  "this instance was used" (see `NoteSchema`'s docblock for the exact
  bug this caused with `domain` before it was fixed).
- **A completely blank row on any sheet is silently skipped**, and a
  row with no `ORG CODE` on "Main Org record" is treated as an
  instructional/example row, not a real organization — don't rely on
  row position, only on `ORG CODE` being present.
