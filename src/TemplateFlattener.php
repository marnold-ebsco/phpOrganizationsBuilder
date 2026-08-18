<?php declare(strict_types=1);

namespace Organizations;

use Organizations\Io\XlsxReader;

/**
 * Flattens a filled-out copy of the multi-sheet Organization_Template.xlsx
 * workbook — one "Main Org record" row per organization, plus one-to-many
 * child sheets (Alt names, Addresses, Phones, Emails, URLs, Contact
 * people, Interfaces, Accounts) joined by "ORG CODE", and a single
 * "Vendor info" row per organization — into the flat, indexed-column row
 * format {@see Mapping\FieldMapper} and {@see RecordBuilder} already
 * understand (`address2_city`, `phoneNumbers[3]`, etc.), one row per
 * organization.
 *
 * This class does *only* the Excel-specific flattening; it doesn't build
 * or validate any records itself — see process_template.php, which feeds
 * {@see flatten()}'s output to bin/build-organizations for that.
 *
 * A few things read from the template have no destination in the FOLIO
 * `organization`/`contact`/`interface` schemas and are deliberately
 * dropped: the "Notes" sheet (no free-text notes field exists on
 * `organization` itself — {@see getDroppedNoteCount()} reports how many
 * were skipped), the "TITLE" column on "Contact people" (not a property
 * of the real `contact` schema — see {@see Schema\ContactSchema}), and
 * the "DESCRIPTION" column on "Interfaces" (see {@see Schema\InterfaceSchema}).
 * "Interfaces"' USERNAME/PASSWORD columns *are* mapped (to
 * `interfaceN_username`/`interfaceN_password`) — bin/build-organizations
 * turns those into a companion `Schema\InterfaceCredentialSchema` record,
 * not part of the interface object itself.
 */
final class TemplateFlattener {
    private int $droppedNoteCount = 0;

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
        $notes = $this->groupByOrgCode($this->readSheetRows($reader, 'Notes', 1));

        $this->droppedNoteCount = array_sum(array_map('count', $notes));

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
            $this->copy($flat, $mainRow, 'ALT NAME', 'alias_value');
            $this->copy($flat, $mainRow, 'Vendor (Yes/No)', 'isVendor');
            $this->copy($flat, $mainRow, 'ORG status (Active/Inactive/Pending)', 'status');
            $this->copy($flat, $mainRow, 'Description', 'description');
            $this->copy($flat, $mainRow, 'ADDR1', 'address_addressLine1');
            $this->copy($flat, $mainRow, 'ADDR2', 'address_addressLine2');
            $this->copy($flat, $mainRow, 'CITY', 'address_city');
            $this->copy($flat, $mainRow, 'REGION', 'address_stateRegion');
            $this->copy($flat, $mainRow, 'POSTAL CODE', 'address_zipCode');
            $this->copy($flat, $mainRow, 'COUNTRY', 'address_country');
            $this->copy($flat, $mainRow, 'PHONE', 'phone_phoneNumber');
            $this->copy($flat, $mainRow, 'EMAIL', 'email_value');
            $this->copy($flat, $mainRow, 'URL', 'url_value');
            $this->copy($flat, $mainRow, 'URL DESCRIPTION', 'url_description');
            $this->copy($flat, $mainRow, 'URL CATEGORY', 'url_categories');
            $this->copy($flat, $mainRow, 'ORG TYPE', 'organizationTypes');
            if (trim((string) ($mainRow['FAX'] ?? '')) !== '') {
                $flat['phone2_phoneNumber'] = trim((string) $mainRow['FAX']);
                $flat['phone2_type'] = 'Fax';
            }

            // Alt names sheet -> aliases 2, 3, ... (ALT NAME on Main Org record is alias 1)
            $index = 2;
            foreach ($altNames[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'ALT NAME', "alias{$index}_value");
                $this->copy($flat, $row, 'Description', "alias{$index}_description");
                $index++;
            }

            // Addresses sheet -> addresses 2, 3, ... (Main Org record's own address is 1)
            $index = 2;
            foreach ($addresses[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'ADDR1', "address{$index}_addressLine1");
                $this->copy($flat, $row, 'ADDR2', "address{$index}_addressLine2");
                $this->copy($flat, $row, 'CITY', "address{$index}_city");
                $this->copy($flat, $row, 'REGION', "address{$index}_stateRegion");
                $this->copy($flat, $row, 'POSTAL CODE', "address{$index}_zipCode");
                $this->copy($flat, $row, 'COUNTRY', "address{$index}_country");
                $this->copy($flat, $row, 'CATEGORY', "address{$index}_categories");
                $index++;
            }

            // Phones sheet -> phones 3, 4, ... (Main Org record's PHONE is 1, FAX is 2)
            $index = 3;
            foreach ($phones[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'PHONE', "phone{$index}_phoneNumber");
                $this->copy($flat, $row, 'TYPE', "phone{$index}_type");
                $this->copy($flat, $row, 'CATEGORY', "phone{$index}_categories");
                $index++;
            }

            // Emails sheet -> emails 2, 3, ... (Main Org record's EMAIL is 1)
            $index = 2;
            foreach ($emails[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'EMAIL', "email{$index}_value");
                $this->copy($flat, $row, 'DESCRIPTION', "email{$index}_description");
                $this->copy($flat, $row, 'CATEGORY', "email{$index}_categories");
                $index++;
            }

            // URLs sheet -> urls 2, 3, ... (Main Org record's URL is 1)
            $index = 2;
            foreach ($urls[$key] ?? [] as $row) {
                $this->copy($flat, $row, 'URL', "url{$index}_value");
                $this->copy($flat, $row, 'DESCRIPTION', "url{$index}_description");
                $this->copy($flat, $row, 'CATEGORY', "url{$index}_categories");
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

    /** @return The number of "Notes" sheet rows found by the last {@see flatten()} call that have no schema destination. */
    public function getDroppedNoteCount(): int {
        return $this->droppedNoteCount;
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
}
