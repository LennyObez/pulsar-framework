# CMS Commerce Module - Legal & Compliance Review (RC.11 Phase 5)

**Reviewer:** Legal & Compliance Specialist
**Date:** 2026-02-19
**Scope:** Tax/VAT, invoicing, GDPR, financial records, digital goods consumer rights
**Status:** Review Complete

---

## 1. Tax/VAT Calculation Review

### 1.1 Rate Lookup Design

The `TaxCalculator` uses `CommerceConfig.taxRates` with per-`tax_category` rate definitions, each carrying `country_codes[]` and a decimal `rate`. Tax is computed at checkout based on the billing address country.

**Assessment: Adequate with recommendations.**

- **Correct:** Rate lookup by `(tax_category, billing_country)` is the standard approach for B2C EU VAT.
- **Correct:** Fallback to zero tax when no matching rate exists is acceptable for non-EU sales, but the `taxRequired: true` flag that rejects checkout without a matching rate is essential for EU sellers - document this as mandatory for EU-based operators.
- **Gap: No tax jurisdiction date-effectiveness.** Tax rates change over time (e.g., Germany temporarily reduced VAT from 19% to 16% in 2020). The current `TaxRateConfig` has no `effective_from` / `effective_until` dates. Orders placed during rate transitions would use whatever rate is configured at checkout time, which is correct for new orders, but historical orders must store the applied rate at time of purchase (addressed in OrderItem - see below). **Recommendation:** Add `effective_from: ?DateTimeImmutable` and `effective_until: ?DateTimeImmutable` to `TaxRateConfig` for operators who need to pre-configure upcoming rate changes. This is not a blocker but is a significant DX improvement for EU operators.

### 1.2 EU VAT Reverse Charge

The plan specifies: if buyer provides a valid VAT number and is in a different EU country, tax rate = 0% with a `reverse_charge` flag on the order.

**Assessment: Correct but requires additional fields.**

- **Correct:** Zero-rating with reverse charge flag aligns with EU VAT Directive Article 196.
- **Gap: The `Order` entity lacks a `vat_number` field.** The buyer's VAT number must be stored on the order for invoice compliance (see Section 2). The current `Order` entity has no field for this. **Recommendation:** Add `?string $vatNumber` and `bool $reverseCharge = false` to the `Order` entity.
- **Gap: No VIES validation.** Checksum validation per country format is a minimum, but EU reverse charge requires that the VAT number is actually valid (registered). The EU provides the VIES (VAT Information Exchange System) SOAP/REST API for real-time validation. While full VIES integration can be a plugin, the `TaxCalculator` should accept a `VatValidatorInterface` with a default `ChecksumVatValidator` and document that EU operators should install a VIES-checking implementation. **Recommendation:** Define `VatValidatorInterface` with `validate(string $vatNumber, string $countryCode): VatValidationResult` and inject it into the tax calculation flow.

### 1.3 VAT Number Format Validation

Per-country VAT number format validation (checksum algorithms) is specified. This covers the 27 EU member states, each with distinct formats (e.g., DE + 9 digits, FR + 2 chars + 9 digits, NL + 9 digits + B + 2 digits).

**Assessment: Adequate for RC.**

- The format-level validation is sufficient as a first gate.
- Document clearly that format validation does NOT confirm active registration - operators selling B2B cross-border must implement VIES verification to safely apply zero-rating.

### 1.4 Rounding Strategy

**Critical compliance point.** EU VAT rules (Council Directive 2006/112/EC, Article 175) require that VAT is calculated per line item, not as a percentage of the order total.

**Assessment: The design correctly stores `tax_amount` on both `Order` AND `OrderItem`.**

- `OrderItem.taxAmount` stores per-item tax in minor currency units.
- `Order.taxAmount` stores the order-level aggregate.
- **Recommendation:** The `TaxCalculator` implementation MUST:
  1. Compute tax per `OrderItem`: `tax = round(unitPrice * quantity * rate)` (round half-up to nearest minor unit).
  2. Sum item-level taxes to derive `Order.taxAmount`.
  3. Never compute order tax as `round(subtotal * rate)` - this produces rounding discrepancies that fail EU tax authority audits.
  4. Document the rounding strategy in a code comment at the calculation site.

### 1.5 Tax Reporting Data Completeness

**Assessment: Adequate.**

- Per-item tax amounts: stored on `OrderItem.taxAmount`.
- Per-order tax totals: stored on `Order.taxAmount`.
- Tax category: stored on `Product.taxCategory` and should be snapshotted in `OrderItem.productSnapshot`.
- **Recommendation:** Ensure `productSnapshot` on `OrderItem` includes: `tax_category`, `applied_tax_rate` (decimal), and `tax_label` (e.g., "VAT 21%"). This is essential for tax reporting and invoice generation. Without the applied rate in the snapshot, it becomes impossible to reconstruct the tax calculation for historical orders if rates change.

---

## 2. Invoice Legal Requirements

### 2.1 Required Fields (EU VAT Directive Article 226)

EU VAT invoices must contain specific mandatory fields. Review against the `Invoice` entity:

| Required Field                       | Status                                    | Notes                                                                                     |
| ------------------------------------ | ----------------------------------------- | ----------------------------------------------------------------------------------------- |
| Sequential invoice number            | Present (`invoiceNumber`)                 | Must be unique and sequential without gaps                                                |
| Date of issue                        | Present (`issuedAt`)                      | Correct                                                                                   |
| Date of supply (if different)        | **Missing**                               | Should default to `issuedAt` but must be specifiable for pre-paid/post-delivery scenarios |
| Seller VAT identification number     | **Missing from Invoice**                  | Must come from site/tenant configuration                                                  |
| Seller name and address              | **Missing from Invoice**                  | Must come from site/tenant configuration                                                  |
| Buyer name and address               | Available via `Order.billingAddress`      | Correct - resolved through order join                                                     |
| Buyer VAT number (if reverse charge) | **Missing from Order**                    | See Section 1.2                                                                           |
| Description of goods/services        | Available via `OrderItem.productSnapshot` | Correct                                                                                   |
| Quantity per item                    | Available via `OrderItem.quantity`        | Correct                                                                                   |
| Unit price (excl. tax)               | Available via `OrderItem.unitPrice`       | Correct                                                                                   |
| Tax rate applied per item            | **Not stored on OrderItem**               | Must be in `productSnapshot` or a dedicated field                                         |
| Tax amount per rate                  | Available via `OrderItem.taxAmount`       | Correct                                                                                   |
| Total amount excl. tax               | Available via `Order.subtotal`            | Correct                                                                                   |
| Total tax amount                     | Available via `Order.taxAmount`           | Correct                                                                                   |
| Total amount incl. tax               | Available via `Order.total`               | Correct                                                                                   |
| Currency                             | Available via `Order.currency`            | Correct                                                                                   |
| "Reverse charge" notation            | **Not stored**                            | Required when reverse charge applies                                                      |
| Payment terms / due date             | Present (`dueAt`)                         | Correct                                                                                   |

**Recommendations:**

1. Add to `CmsConfig` or `CommerceConfig`: `sellerName`, `sellerAddress`, `sellerVatNumber`, `sellerRegistrationNumber` - these are mandatory on every invoice.
2. Add to `Order`: `?string $vatNumber`, `bool $reverseCharge`.
3. Add to `OrderItem` or its `productSnapshot`: `taxRate` (decimal), `taxLabel` (string).
4. The invoice template (HTML or PDF) must render ALL of the above fields. The `HtmlInvoiceRenderer` template should be reviewed against this checklist.
5. For reverse charge invoices, the template must include the text "Reverse charge - VAT to be accounted for by the recipient" (or equivalent in the invoice locale).

### 2.2 Sequential Immutable Numbering

The plan specifies `invoice_number` as "sequential, immutable" with type `string(50)`.

**Assessment: Correct intent, implementation details needed.**

- Several EU jurisdictions (France, Italy, Germany, Belgium) require sequential invoice numbering with no gaps. A gap in the sequence raises audit flags.
- **Recommendation:** The `InvoiceService` must use a database-level sequence or `SELECT FOR UPDATE` counter to guarantee no gaps even under concurrent invoice generation. Application-level incrementing (read max + 1) has race conditions.
- **Recommendation:** Invoice numbers should include a year prefix (e.g., `2026-00001`) to allow annual sequence resets, which is standard practice and accepted by all EU jurisdictions.
- **Recommendation:** Once an invoice number is assigned, it must NEVER be reused or reassigned. The `Invoice` entity is already `readonly`, which is correct. The database should have a `UNIQUE` constraint on `(tenant_id, invoice_number)`.
- **Credit notes:** When a refund occurs, a credit note (negative invoice) must be generated with its own sequential number referencing the original invoice. The current design does not include a `CreditNote` entity or a `type` field on `Invoice`. **Recommendation:** Add `InvoiceType` enum (`invoice`, `credit_note`) and `?string $referenceInvoiceId` to `Invoice` for credit notes.

### 2.3 Evidence Hash for Tamper Detection

The plan specifies `evidence_hash` (SHA-256 of invoice data) and `pdf_hash` (BLAKE2b of PDF).

**Assessment: Good design with one correction needed.**

- The `Invoice` entity currently documents `pdfHash` as "SHA-256" but the plan specifies BLAKE2b. The implementation should use BLAKE2b consistently with the rest of the audit chain (which uses HMAC-BLAKE2b).
- **Recommendation:** The `evidence_hash` should cover: `invoice_number`, `order_id`, `issued_at`, `due_at`, all line item data (product, quantity, unit price, tax), totals, seller details, and buyer details. Document the exact field set that feeds the hash.
- **Recommendation:** The evidence hash should be computed using `HMAC-BLAKE2b` with the audit key (consistent with the audit chain), not plain SHA-256.

### 2.4 Record Retention

EU jurisdictions require financial record retention between 6-10 years:

| Jurisdiction | Retention Period                                               |
| ------------ | -------------------------------------------------------------- |
| France       | 10 years (Code de commerce L123-22)                            |
| Germany      | 10 years (AO Section 147)                                      |
| UK           | 6 years (HMRC)                                                 |
| Netherlands  | 7 years (AWR Article 52)                                       |
| Italy        | 10 years (Civil Code Article 2220)                             |
| Belgium      | 7 years (Code TVA Article 60)                                  |
| Spain        | 4 years (Ley General Tributaria), 6 years (Codigo de Comercio) |

**Assessment:** The "never delete" policy for financial records (plan Section I.5) exceeds all jurisdiction requirements. This is the correct approach - infinite retention for financial records is simpler and safer than per-jurisdiction TTLs.

**Recommendation:** Document the retention policy explicitly in a `CommerceConfig.financialRecordRetentionPolicy` constant or config value, even if the value is "permanent." This provides operators with auditable evidence that a retention policy exists and has been configured.

---

## 3. Financial Record Retention Policy

### 3.1 "Never Delete" Policy

**Assessment: Correct and compliant.**

The plan states: "Financial records (orders, invoices, payment events) follow a 'never delete' policy - soft delete only, with audit trail."

This aligns with:

- EU VAT Directive Article 244: member states must ensure storage of invoices for the retention period.
- National accounting laws (see Section 2.4 table).
- PCI DSS Requirement 3.1: retain cardholder data only as long as needed - but the CMS does NOT store card data (delegated to `pulsar/payments`), so this is not applicable.

### 3.2 Soft Delete Implementation

**Assessment: Needs explicit implementation.**

- The `Order` entity is `readonly` and lacks a `deleted_at` field. The plan mentions soft delete but the entity schema (Section B.1) does not include `deleted_at` or `is_deleted` on orders/invoices.
- **Recommendation:** For financial records, soft delete should be implemented at the repository level (query filter), not as a column. Financial records should genuinely never be soft-deleted either - the "never delete" policy should mean exactly that. Admin can archive/close orders but never delete them. The `OrderStatus::Cancelled` state is sufficient for orders that should not be fulfilled.
- **Recommendation:** Add a database-level trigger or application check that prevents `DELETE` statements on `cms_orders`, `cms_order_items`, and `cms_invoices`. A `BEFORE DELETE` trigger that raises an exception is a robust safety net.

### 3.3 Audit Trail Completeness

**Assessment: Good coverage.**

The audit events table (plan Section I.5) covers:

- Order creation: `cms.order.created`
- Payment received: `cms.payment.received` (permanent retention)
- Refund processed: `cms.order.refunded` (permanent retention)
- Invoice generated: `cms.invoice.generated` (permanent retention)
- Webhook received: `cms.payment.webhook` (90-day retention)

**Gap:** The following financial operations should also generate audit events:

- `cms.order.status_changed` - every status transition (not just creation)
- `cms.order.cancelled` - with reason and cancelled_by
- `cms.invoice.credit_note_generated` - when credit notes are implemented
- `cms.order.exported` - when order data is exported (accounting export)
- `cms.order.pii_accessed` - when decrypted PII fields are accessed by admin

**Recommendation:** Add these audit events to the specification and mark all financial audit events as "permanent" retention.

### 3.4 GDPR Article 17(3)(b) Exception

**Assessment: Correctly identified and applied.**

GDPR Article 17(3)(b) provides an exception to the right to erasure for "compliance with a legal obligation which requires processing by Union or Member State law." Financial record retention laws constitute exactly such a legal obligation.

**Recommendation:** The pseudonymization approach (email to hash, addresses to "[redacted]") correctly balances GDPR erasure rights with financial retention obligations. Document this explicitly in operator-facing compliance documentation with the specific legal basis citation.

---

## 4. GDPR Compliance on Order Data

### 4.1 PII Encrypted at Rest

**Assessment: Correct design.**

The plan classifies `Order` as `DataClassification::Pii` (always) and specifies encryption at rest for:

- `customer_email` (string, encrypted at rest)
- `billing_address` (JSON, encrypted at rest)
- `shipping_address` (JSON, encrypted at rest)

The `Order` entity correctly defaults `dataClassification` to `DataClassification::Pii` in `Order::create()`.

**Recommendation:** Ensure the encryption implementation:

1. Uses authenticated encryption (AEAD) - the plan's crypto policy specifies XChaCha20-Poly1305, which is correct.
2. Encrypts individual fields, not the entire row - this allows queries on non-PII columns without decryption.
3. Stores a key version identifier with encrypted data to support key rotation.
4. Logs every decryption as an audit event for PII access monitoring.

### 4.2 Right to Erasure: Pseudonymization

**Assessment: Correct approach.**

The plan (Section G.8, GDPR) specifies:

- GDPR deletion requests pseudonymize order PII: `customer_email` to hash, addresses to `"[redacted]"`.
- Order records, invoices, and payment records are retained for accounting compliance.
- Mandatory `reason` and audit event on erasure.

**Recommendations:**

1. **Pseudonymization must be irreversible.** The email hash should use a keyed hash (HMAC) with a key that is destroyed after the pseudonymization batch completes, OR use a one-way hash with a per-erasure salt that is not stored. Simply hashing with SHA-256 without a key is reversible via rainbow tables for common email addresses.
2. **All PII fields must be covered.** Beyond email and addresses, check for PII in:
  - `Order.notes` (admin notes may contain customer names/details - redact)
  - `OrderItem.productSnapshot` (if it contains customer-specific data like personalization - redact)
  - `Invoice.pdfStoragePath` (the stored PDF/HTML contains customer PII - the file must be regenerated with redacted data or deleted with a note in the audit trail)
3. **Cross-reference with comments.** If the same customer left comments, the comment PII erasure must run as part of the same operation. The `ToolsServiceInterface::eraseUserData(userId, reason)` should handle this atomically.
4. **Response timeline.** GDPR Article 12(3) requires response within one month. Document this SLA for operators.

### 4.3 Data Export (GDPR Article 20 - Portability)

**Assessment: Adequate design.**

`ToolsServiceInterface::exportUserData(userId)` exports all user data in portable JSON format. Step-up authentication is required for PII fields.

**Recommendations:**

1. The export must include: orders, order items, invoices, digital download records, comments, and any content authored by the user.
2. Export format should be machine-readable (JSON is correct) and well-documented.
3. The export itself is an audit event (`cms.user.data_exported`) and must be logged.
4. Rate-limit data export requests (once per 24 hours per user is reasonable) to prevent abuse.

### 4.4 Data Classification Propagation

**Assessment: Correct.**

- `Order` is always `DataClassification::Pii` - hardcoded in `Order::create()`.
- `Invoice` is always `DataClassification::Pii`.
- `Comment` is always `DataClassification::Pii`.
- Classification drives encryption-at-rest, access logging, and export behavior.

No changes needed.

---

## 5. Digital Goods Consumer Rights

### 5.1 EU Consumer Rights Directive (2011/83/EU)

For digital content (not supplied on a tangible medium), the consumer has a 14-day right of withdrawal UNLESS:

1. The supply has begun with the consumer's prior express consent, AND
2. The consumer has acknowledged that they thereby lose their right of withdrawal.

**Assessment: Not addressed in the current design.**

The `DigitalDownload` entity tracks download tokens, expiry, and download counts, but the checkout flow does not capture explicit consent for digital goods withdrawal waiver.

**Recommendations:**

1. **Add a consent checkbox on checkout** (required for digital products): "I agree that the download begins immediately and I acknowledge that I lose my right of withdrawal once the download has started." This must be a separate, non-pre-ticked checkbox.
2. **Store the consent timestamp** on the `Order` or `DigitalDownload`: `?DateTimeImmutable $withdrawalWaiverConsentAt`. This is evidence that consent was given.
3. **Before first download:** If `withdrawalWaiverConsentAt` is null, prevent the download and prompt for consent. This is a safety net for cases where the checkout flow was bypassed.
4. **Refund logic:** If a digital product has not been downloaded (download count = 0) and is within 14 days, the consumer has a right to a full refund regardless of the consent checkbox. The `OrderService::refund()` method should check this.
5. **Mixed orders:** If an order contains both physical and digital products, the withdrawal right applies separately to each. Physical products have a 14-day return right from delivery; digital products follow the consent-based waiver.

### 5.2 Download Token Security

**Assessment: Adequate design.**

- Tokens are 128-character strings (sufficient entropy).
- Tokens have expiry (`expires_at`).
- Download count is decremented on each use.
- Downloads are audit-logged.

**Recommendations:**

1. Tokens must be generated using a CSPRNG (`random_bytes()`), not a predictable source.
2. Token validation must be constant-time (`hash_equals()`) to prevent timing attacks.
3. Expired or exhausted tokens must return 404 (not 403) to avoid information leakage about the existence of the asset.
4. Rate-limit download attempts per token to prevent brute-force enumeration.

### 5.3 Refund Implications for Digital Deliveries

**Assessment: Needs design consideration.**

When a refund is processed for a digital order:

1. All associated `DigitalDownload` tokens must be immediately invalidated (`downloads_remaining = 0`, `expires_at = now`).
2. The refund audit event must reference the invalidated tokens.
3. If the file has already been downloaded, this is noted in the refund record but the refund is still processed (the consumer may have a legal right to a refund even after download, depending on jurisdiction and whether the product was defective).

---

## 6. Summary of Recommendations

### Critical (Must Fix Before GA)

| #   | Issue                                     | Affected Entity    | Recommendation                                                                                             |
| --- | ----------------------------------------- | ------------------ | ---------------------------------------------------------------------------------------------------------- |
| C1  | Missing seller identification on invoices | `CommerceConfig`   | Add `sellerName`, `sellerAddress`, `sellerVatNumber`, `sellerRegistrationNumber` config fields             |
| C2  | Missing buyer VAT number on orders        | `Order`            | Add `?string $vatNumber` and `bool $reverseCharge` fields                                                  |
| C3  | Missing per-item tax rate storage         | `OrderItem`        | Ensure `productSnapshot` includes `tax_category`, `applied_tax_rate`, `tax_label`, or add dedicated fields |
| C4  | No credit note support for refunds        | `Invoice`          | Add `InvoiceType` enum and `?string $referenceInvoiceId` for credit notes                                  |
| C5  | Per-item tax rounding                     | `TaxCalculator`    | Implement per-item rounding (not order-total rounding) per EU VAT rules                                    |
| C6  | Digital goods withdrawal consent          | `Order` / Checkout | Add withdrawal waiver consent capture, storage, and enforcement                                            |

### Important (Should Fix Before GA)

| #   | Issue                                 | Affected Area    | Recommendation                                                                                                 |
| --- | ------------------------------------- | ---------------- | -------------------------------------------------------------------------------------------------------------- |
| I1  | Invoice evidence hash algorithm       | `Invoice`        | Use HMAC-BLAKE2b (consistent with audit chain), not SHA-256                                                    |
| I2  | Missing financial audit events        | Audit            | Add `order.status_changed`, `order.cancelled`, `credit_note.generated`, `order.exported`, `order.pii_accessed` |
| I3  | DELETE prevention on financial tables | Database         | Add `BEFORE DELETE` triggers on `cms_orders`, `cms_order_items`, `cms_invoices`                                |
| I4  | Invoice number gap prevention         | `InvoiceService` | Use database-level sequence or `SELECT FOR UPDATE` counter                                                     |
| I5  | VAT validator interface               | `TaxCalculator`  | Define `VatValidatorInterface` for VIES integration extensibility                                              |
| I6  | Pseudonymization irreversibility      | `ToolsService`   | Use keyed hash with destroyed key or per-erasure salt                                                          |

### Nice to Have (Post-GA)

| #   | Issue                             | Recommendation                                               |
| --- | --------------------------------- | ------------------------------------------------------------ |
| N1  | Tax rate date-effectiveness       | Add `effective_from` / `effective_until` to `TaxRateConfig`  |
| N2  | Financial record retention config | Add `CommerceConfig.financialRecordRetentionPolicy` constant |
| N3  | Data export rate limiting         | Limit to once per 24 hours per user                          |
| N4  | Supply date on invoices           | Add `?DateTimeImmutable $supplyDate` to `Invoice`            |

### Documentation Requirements for Site Operators

The following must be documented in a CMS Commerce Compliance Guide:

1. **EU sellers MUST configure `taxRequired: true`** to prevent tax-free checkout within the EU.
2. **Seller identification** (name, address, VAT number) must be configured before enabling commerce.
3. **VAT reverse charge** requires a VIES-validating plugin for production B2B use; format-only validation is insufficient.
4. **Invoice numbering** resets annually by default; operators must not manually manipulate the sequence.
5. **Financial records are permanent** and cannot be deleted. GDPR erasure pseudonymizes PII but retains records.
6. **Digital goods** require the withdrawal consent checkbox; disabling it violates EU consumer protection law.
7. **Data export** requests must be fulfilled within one month per GDPR Article 12(3).
8. **Record retention** meets or exceeds all EU jurisdiction requirements (permanent retention).

---

**Conclusion:** The commerce module design is fundamentally sound for a framework-level commerce system. The critical gaps are primarily around invoice completeness (seller ID, buyer VAT, credit notes) and digital goods consumer rights (withdrawal consent). These are addressable within the current architecture without redesign. The financial record handling, PII encryption, and audit trail design are well-architected for regulated environments.
