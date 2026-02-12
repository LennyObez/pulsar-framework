# CMS phase 4 security review (RC5)

> **Deprecation notice (RC.11):** This review was conducted against RC5. The findings have been addressed in subsequent releases. Verify current status against the source files listed in the "Files modified" section before relying on this document.

Scope: CSS validator, CSP hash computation, PII handling, digital download tokens, webhook security, refund security, checkout security.

## Summary

7 vulnerabilities found and fixed, 1 informational note.

---

## Findings

### 1. CSS validator: missing `@charset` block, whitespace bypass, null byte evasion

**Severity:** Medium
**File:** `extensions/cms/src/Internal/LiveCss/CssValidator.php`

**Issues found:**

- `@charset` was not blocked. An attacker could use `@charset` to change the encoding context, potentially enabling bypass vectors.
- Whitespace compaction only collapsed spaces before `(`. Non-breaking spaces (`\x00A0`) and other Unicode whitespace could bypass pattern matching (e.g., `expres\xA0sion(`).
- Null bytes (`\0`) were not stripped. Some parsers skip nulls, allowing `java\0script:` to become `javascript:` in the browser.
- `vbscript:` protocol was not blocked (legacy IE vector).
- `url()` did not check for `javascript:` or `vbscript:` inside the function call.
- No input size limit - a maliciously large input could cause pathological regex backtracking.

**Fixes applied:**

- Added `@charset` to blocked constructs.
- Changed whitespace compaction from `\s+\(` to full whitespace collapse `[\s\x{00A0}]+` -> `''` (removes ALL whitespace for detection, since `$compacted` is only used for pattern checks).
- Added null byte stripping before pattern matching.
- Added `vbscript:` detection.
- Added `javascript:` and `vbscript:` to the `containsExternalUrl()` regex.
- Added 512 KB size limit.

---

### 2. CSP hash computer: no issues

**Severity:** None
**File:** `extensions/cms/src/Internal/LiveCss/CspHashComputer.php`

The implementation correctly uses `hash('sha256', $content, true)` with binary output + `base64_encode`, producing the standard `sha256-{base64}` format required by CSP `style-src`. No timing attacks apply here (hash computation, not comparison).

---

### 3. PII redaction: missing `billing_address` and `shipping_address`

**Severity:** High
**File:** `extensions/cms/src/Internal/Tools/ExportBundleGenerator.php`

**Issue:** The `PII_FIELDS` constant was missing `billing_address` and `shipping_address`. Exports with `includePii: false` would still contain full customer addresses as JSON objects. This is a GDPR compliance issue.

**Fix:** Added `billing_address` and `shipping_address` to the PII_FIELDS list.

---

### 4. Backup service: timing-unsafe hash comparison

**Severity:** Low
**File:** `extensions/cms/src/Internal/Tools/BackupService.php`

**Issue:** Hash verification on restore used `!==` (string comparison) instead of `hash_equals()`. While exploitation requires an attacker to make many restore attempts to leak hash bytes via timing side-channel, the fix is trivial and follows best practices.

**Fix:** Changed to `hash_equals($expectedHash, $actualHash)`.

**Additional observations:** Hash verification and reason requirement are correctly implemented. Restore operates within a transaction.

---

### 5. Digital delivery: TOCTOU race condition on download count

**Severity:** High
**File:** `extensions/cms/src/Internal/Commerce/DigitalDeliveryService.php`

**Issue:** `processDownload()` called `validateToken()` (which reads `downloadsRemaining` from DB), then called `decrementDownloads()` separately. Between the validation read and the atomic decrement, a concurrent request could also pass validation, leading to more downloads than the limit allows.

**Fix:**

- Changed `decrementDownloads()` return type from `void` to `int` (affected row count).
- `processDownload()` now uses the atomic decrement result as the authoritative check. If `decrementDownloads()` returns 0, the download is rejected.
- Updated `DigitalAssetRepositoryInterface` and `DbDigitalAssetRepository` accordingly.

The SQL `WHERE downloads_remaining > 0` clause makes the decrement atomic, but only if we check the result.

---

### 6. Refund over-spend: no cumulative refund tracking

**Severity:** Critical
**File:** `extensions/cms/src/Internal/Commerce/OrderService.php`

**Issue:** The refund method checked `$amount > $order->total` but did not track how much had already been refunded. Multiple partial refunds of `$order->total - 1` would all pass, allowing total refunds to far exceed the order value.

**Fix:**

- Added `amount_refunded BIGINT NOT NULL DEFAULT 0` column to `cms_orders` table.
- Added DB constraints: `CHECK (amount_refunded >= 0)` and `CHECK (amount_refunded <= total)`.
- Added `amountRefunded` property to `Order` model.
- Refund method now atomically increments `amount_refunded` with a ceiling check: `WHERE (amount_refunded + :amount) <= total`. If the atomic UPDATE affects 0 rows, the refund is rejected.
- Added validation that refund amount must be positive.

**Files updated:**

- `extensions/cms/src/Migration/015_create_cms_orders.php` (schema)
- `extensions/cms/src/Commerce/Order.php` (model)
- `extensions/cms/src/Internal/Persistence/DbOrderRepository.php` (repository SQL + hydration)
- `extensions/cms/src/Internal/Commerce/OrderService.php` (refund logic)
- `extensions/cms/src/Internal/Commerce/CheckoutService.php` (Order construction)

---

### 7. Webhook handler: correctly idempotent

**Severity:** None
**File:** `extensions/cms/src/Internal/Commerce/WebhookHandler.php`

- Signature verification is the first operation.
- `handlePaymentSucceeded` checks `$order->status === OrderStatus::Confirmed || OrderStatus::Fulfilled` before processing - correctly idempotent.
- `handlePaymentFailed` and `handleChargeRefunded` operate correctly.
- Unrecognized webhook types are logged but not processed.

No issues found.

---

### 8. Checkout security: correctly implemented

**Severity:** None
**File:** `extensions/cms/src/Internal/Commerce/CheckoutService.php`

- Stock reservation uses atomic `UPDATE ... WHERE stock_quantity >= :qty` (line 147). If it returns 0 affected rows, a `CmsException` is thrown.
- Price snapshot: `validateCart()` compares `$product->priceAmount !== $item['unitPrice']` and rejects mismatches.
- Order creation happens within `$this->db->transaction()`.
- Product snapshot (`productSnapshot`) is captured at order time and stored with the order item.

No issues found.

---

## Files modified

| File                                                                   | Change                                                                |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------- |
| `extensions/cms/src/Internal/LiveCss/CssValidator.php`                 | @charset block, vbscript, null bytes, whitespace collapse, size limit |
| `extensions/cms/src/Internal/Tools/ExportBundleGenerator.php`          | Added billing_address, shipping_address to PII_FIELDS                 |
| `extensions/cms/src/Internal/Tools/BackupService.php`                  | hash_equals() for timing-safe comparison                              |
| `extensions/cms/src/Internal/Commerce/DigitalDeliveryService.php`      | TOCTOU fix: check decrementDownloads return value                     |
| `extensions/cms/src/Commerce/DigitalAssetRepositoryInterface.php`      | decrementDownloads returns int                                        |
| `extensions/cms/src/Internal/Persistence/DbDigitalAssetRepository.php` | decrementDownloads returns int                                        |
| `extensions/cms/src/Internal/Commerce/OrderService.php`                | Atomic cumulative refund tracking                                     |
| `extensions/cms/src/Commerce/Order.php`                                | Added amountRefunded property                                         |
| `extensions/cms/src/Internal/Persistence/DbOrderRepository.php`        | amount_refunded in SQL and hydration                                  |
| `extensions/cms/src/Internal/Commerce/CheckoutService.php`             | amountRefunded: 0 in Order construction                               |
| `extensions/cms/src/Migration/015_create_cms_orders.php`               | amount_refunded column + constraints                                  |
