<?php declare(strict_types=1);

namespace Organizations;

use Organizations\Io\XlsxReader;

/**
 * Flattens a filled-out copy of the alternate workbook,
 * Organization_Template_Alternate.xlsx, into the exact same flat,
 * indexed-column row format {@see TemplateFlattener} produces
 * (`address2_city`, `phoneNumbers[3]`, etc.) — see
 * {@see Mapping\FieldMapper} and {@see RecordBuilder} — so
 * organization_field_mapping.json and bin/build-organizations need no
 * changes at all to build from either template.
 *
 * The alternate workbook differs from the original in one structural way:
 * "Main Org record" holds *only* fields that can't repeat (code, name,
 * isVendor, status, description) — every alias/address/phone/email/url,
 * including what the original template calls the "primary" one, is a row
 * on its own dedicated sheet (Alt names/Addresses/Phones/Emails/URLs),
 * numbered starting at instance 1 rather than 2 (or 3, for phones, since
 * there's no more Main-Org-record PHONE/FAX pair to reserve instances 1-2
 * for). Addresses/Phones/Emails/URLs also each gained an "IS PRIMARY"
 * (Yes/No) column, since the real schema's `isPrimary` flag has nowhere
 * else to come from once there's no single implicit "primary" slot on
 * Main Org record. Every other sheet (Contact people, Interfaces, Vendor
 * info, Accounts) is unchanged from the original template and is
 * flattened identically. An optional "ORG TYPE" column on Main Org
 * record (mapping to `organizationTypes`, same as the original
 * template's own example data) is read if present, same rationale as
 * "Vendor info"'s "CURRENCIES" column: `organizationTypes` is a list
 * field, but it's a single pipe-delimited cell, not a one-row-per-
 * instance repeatable group, so it has no dedicated sheet of its own and
 * stays put on Main Org record. Neither template has a "Notes" sheet:
 * the real `organization` schema has no general-purpose free-text notes
 * field anywhere (the only `notes` property in the whole schema is
 * `edi.notes`, specific to EDI transmission configuration).
 */
final class AlternateTemplateFlattener {
    /**
     * Read every sheet and build one flattened row per organization.
     *
     * @return list<array<string, string>> One row per organization found
     *         on the "Main Org record" sheet (rows with no ORG CODE are
     *         skipped — the template's own instructional rows have none).
     */
    public function flatten(XlsxReader $reader): array {
        $mainRows = $this->readSheetRows($reader, 'Main Org record', 5);
        $altNames = $this->groupByOrgCode($this->readSheetRows($reader, 'Alt names', 1));
        $addresses = $this->groupByOrgCode($this->readSheetRows($reader, 'Addresses', 1));
        $phones = $this->groupByOrgCode($this->readSheetRows($reader, 'Phones', 1));
        $emails = $this->groupByOrgCode($this->readSheetRows($reader, 'Emails', 1));
        $urls = $this->groupByOrgCode($this->readSheetRows($reader, 'URLs', 1));
        $contacts = $this->groupByOrgCode($this->readSheetRows($reader, 'Contact people', 1));
        $interfaces = $this->groupByOrgCode($this->readSheetRows($reader, 'Interfaces', 1));
        $vendorInfo = $this->groupByOrgCode($this->readSheetRows($reader, 'Vendor info', 1));
        $accounts = $this->groupByOrgCode($this->readSheetRows($reader, 'Accounts', 1));

        $flatRows = [];
        foreach ($mainRows as $mainRow) {
            $code = trim((string) ($mainRow['ORG CODE'] ?? ''));
            if ($code === '') {
                continue; // instructional/blank row, not a real organization
            }
            $key = strtolower($code);
            $flat = [];

            $this->copy($flat, $mainRow, 'ORG CODE', 'code');
            $this->copy($flat, $mainRow, 'ORG NAME', 'name');
            $this->copy($flat, $mainRow, 'Vendor (Yes/No)', 'isVendor');
            $this->copy($flat, $mainRow, 'ORG status (Active/Inactive/Pending)', 'status');
            $this->copy($flat, $mainRow, 'Description', 'description');
            $this->copy($flat, $mainRow, 'ORG TYPE', 'organizationTypes');

            // Alt names sheet -> aliases 1, 2, ... (no Main Org record slot to reserve)
            $index = 1;
            foreach ($altNames[$key] ?? [] as $row) {
                $prefix = $this->instancePrefix('alias', $index);
                $this->copy($flat, $row, 'ALT NAME', "{$prefix}value");
                $this->copy($flat, $row, 'Description', "{$prefix}description");
                $index++;
            }

            // Addresses sheet -> addresses 1, 2, ...
            $index = 1;
            foreach ($addresses[$key] ?? [] as $row) {
                $prefix = $this->instancePrefix('address', $index);
                $this->copy($flat, $row, 'ADDR1', "{$prefix}addressLine1");
                $this->copy($flat, $row, 'ADDR2', "{$prefix}addressLine2");
                $this->copy($flat, $row, 'CITY', "{$prefix}city");
                $this->copy($flat, $row, 'REGION', "{$prefix}stateRegion");
                $this->copy($flat, $row, 'POSTAL CODE', "{$prefix}zipCode");
                $this->copy($flat, $row, 'COUNTRY', "{$prefix}country");
                $this->copy($flat, $row, 'CATEGORY', "{$prefix}categories");
                $this->copy($flat, $row, 'IS PRIMARY', "{$prefix}isPrimary");
                $index++;
            }

            // Phones sheet -> phones 1, 2, ... (a Fax number is just a normal
            // row with TYPE=Fax now, not a special Main-Org-record column)
            $index = 1;
            foreach ($phones[$key] ?? [] as $row) {
                $prefix = $this->instancePrefix('phone', $index);
                $this->copy($flat, $row, 'PHONE', "{$prefix}phoneNumber");
                $this->copy($flat, $row, 'TYPE', "{$prefix}type");
                $this->copy($flat, $row, 'CATEGORY', "{$prefix}categories");
                $this->copy($flat, $row, 'IS PRIMARY', "{$prefix}isPrimary");
                $index++;
            }

            // Emails sheet -> emails 1, 2, ...
            $index = 1;
            foreach ($emails[$key] ?? [] as $row) {
                $prefix = $this->instancePrefix('email', $index);
                $this->copy($flat, $row, 'EMAIL', "{$prefix}value");
                $this->copy($flat, $row, 'DESCRIPTION', "{$prefix}description");
                $this->copy($flat, $row, 'CATEGORY', "{$prefix}categories");
                $this->copy($flat, $row, 'IS PRIMARY', "{$prefix}isPrimary");
                $index++;
            }

            // URLs sheet -> urls 1, 2, ...
            $index = 1;
            foreach ($urls[$key] ?? [] as $row) {
                $prefix = $this->instancePrefix('url', $index);
                $this->copy($flat, $row, 'URL', "{$prefix}value");
                $this->copy($flat, $row, 'DESCRIPTION', "{$prefix}description");
                $this->copy($flat, $row, 'CATEGORY', "{$prefix}categories");
                $this->copy($flat, $row, 'IS PRIMARY', "{$prefix}isPrimary");
                $index++;
            }

            // Contact people sheet -> contacts 1, 2, ... (own top-level records, not nested)
            // TITLE has no home in the real `contact` schema and is dropped.
            $index = 1;
            foreach ($contacts[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'FIRST NAME', "contact{$index}_firstName");
                $this->copy($flat, $row, 'LAST NAME', "contact{$index}_lastName");
                $this->copy($flat, $row, 'NOTES', "contact{$index}_notes");
                $this->copy($flat, $row, 'CATEGORY', "contact{$index}_categories");
                $this->copy($flat, $row, 'EMAIL', "contact{$index}_email");
                $this->copy($flat, $row, 'DESCRIPTION', "contact{$index}_emailDescription");
                $this->copy($flat, $row, 'PHONE', "contact{$index}_phone");
                $index++;
            }

            // Interfaces sheet -> interfaces 1, 2, ... (own top-level records, not nested).
            // USERNAME/PASSWORD feed a companion interfaceCredential record
            // (built by bin/build-organizations, not embedded here) rather
            // than the interface record itself. "DESCRIPTION" still has no
            // home in the real `interface` schema and is dropped.
            $index = 1;
            foreach ($interfaces[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'NAME', "interface{$index}_name");
                $this->copy($flat, $row, 'TYPE', "interface{$index}_type");
                $this->copy($flat, $row, 'URL', "interface{$index}_uri");
                $this->copy($flat, $row, 'DELIVERY METHOD', "interface{$index}_deliveryMethod");
                $this->copy($flat, $row, 'NOTES', "interface{$index}_notes");
                $this->copy($flat, $row, 'USERNAME', "interface{$index}_username");
                $this->copy($flat, $row, 'PASSWORD', "interface{$index}_password");
                $index++;
            }

            // Vendor info sheet -> top-level scalar/list fields (one row per organization)
            foreach ($vendorInfo[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'PAYMENT METHOD', 'paymentMethod');
                $this->copy($flat, $row, 'CURRENCIES', 'vendorCurrencies');
                $this->copy($flat, $row, 'CLAIMING INTERVAL', 'claimingInterval');
                $this->copy($flat, $row, 'DISCOUNT %', 'discountPercent');
                $this->copy($flat, $row, 'EXP ACTIVATION INTERVAL', 'expectedActivationInterval');
                $this->copy($flat, $row, 'EXP INVOICE INTERVAL', 'expectedInvoiceInterval');
                $this->copy($flat, $row, 'EXP RECEIPT INTERVAL', 'expectedReceiptInterval');
                $this->copy($flat, $row, 'RENEWAL ACTIVATION INTERVAL', 'renewalActivationInterval');
                $this->copy($flat, $row, 'SUBSCRIPTION INTERVAL', 'subscriptionInterval');
                $this->copy($flat, $row, 'EXPORT TO ACCOUNTING (Y/)', 'exportToAccounting');
                $this->copy($flat, $row, 'TAX ID', 'taxId');
                $this->copy($flat, $row, 'TAX %', 'taxPercentage');
                $this->copy($flat, $row, 'LIABLE FOR VAT (Y/N)', 'liableForVat');
                break; // only one Vendor info row per organization is meaningful
            }

            // Accounts sheet -> accounts 1, 2, ...
            $index = 1;
            foreach ($accounts[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'ACCOUNT NAME', "account{$index}_name");
                $this->copy($flat, $row, 'ACCOUNT NUMBER', "account{$index}_accountNo");
                $this->copy($flat, $row, 'DESCRIPTION', "account{$index}_description");
                $this->copy($flat, $row, 'ACCOUNTING CODE', "account{$index}_appSystemNo");
                $this->copy($flat, $row, 'PAYMENT METHOD', "account{$index}_paymentMethod");
                $this->copy($flat, $row, 'ACCOUNT STATUS', "account{$index}_accountStatus");
                $this->copy($flat, $row, 'LIBRARY EDI CODE', "account{$index}_libraryEdiCode");
                $this->copy($flat, $row, 'NOTES', "account{$index}_notes");
                $index++;
            }

            $flatRows[] = $flat;
        }

        return $flatRows;
    }

    /**
     * Read one sheet into a list of associative rows keyed by its own
     * header row (trimmed, matched exactly as written in the template).
     *
     * @return list<array<string, string>>
     */
    private function readSheetRows(XlsxReader $reader, string $sheetName, int $headerRow): array {
        $raw = $reader->readSheet($sheetName);
        if (!isset($raw[$headerRow])) {
            return [];
        }
        $headers = array_map(static fn($h) => trim((string) $h), $raw[$headerRow]);

        $rows = [];
        foreach ($raw as $rowNum => $row) {
            if ($rowNum <= $headerRow) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $col => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = trim((string) ($row[$col] ?? ''));
            }
            if (implode('', $assoc) === '') {
                continue; // fully blank row
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    /** Group a sheet's rows by their "ORG CODE" column (case-insensitive), preserving order. */
    private function groupByOrgCode(array $rows): array {
        $grouped = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['ORG CODE'] ?? ''));
            if ($code === '') {
                continue;
            }
            $grouped[strtolower($code)][] = $row;
        }
        return $grouped;
    }

    /** Copy `$row[$sourceHeader]` into `$flat[$legacyField]` if non-empty. */
    private function copy(array &$flat, array $row, string $sourceHeader, string $legacyField): void {
        $value = trim((string) ($row[$sourceHeader] ?? ''));
        if ($value !== '') {
            $flat[$legacyField] = $value;
        }
    }

    /**
     * The legacy-field prefix for one instance of aliases/addresses/
     * phoneNumbers/emails/urls, e.g. `instancePrefix('address', 1)` ===
     * `'address_'`, `instancePrefix('address', 2)` === `'address2_'`.
     *
     * Matches organization_field_mapping.json's existing convention for
     * these five groups specifically: instance 1 has *no* number (its
     * mapping entries are the bracket-less `addresses.city` form, which
     * {@see \Organizations\Mapping\FieldMapper} normalizes to
     * `addresses[1].city` but whose `legacy_field` is `address_city`,
     * not `address1_city`) — a holdover from when instance 1 always
     * came from Main Org record's own ADDR1/PHONE/EMAIL/URL/ALT NAME
     * columns, not a sheet row. Contacts/interfaces/accounts don't need
     * this: their mapping entries number every instance, including the
     * first, since they never had a Main-Org-record slot to begin with.
     */
    private function instancePrefix(string $singular, int $index): string {
        return $index === 1 ? "{$singular}_" : "{$singular}{$index}_";
    }
}
