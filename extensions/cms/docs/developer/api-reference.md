# API endpoint reference

All CMS API endpoints are registered by `CmsExtension::boot()`. Admin endpoints require authentication and appropriate CMS permissions.

## Public endpoints

### Content rendering

| Method | Path               | Route Name                  | Auth | Description                                                            |
| ------ | ------------------ | --------------------------- | ---- | ---------------------------------------------------------------------- |
| GET    | `/{path}`          | `cms.content.show`          | No   | Render content for default locale (when `defaultLocaleInUrl` is false) |
| GET    | `/{locale}/{path}` | `cms.content.show.{locale}` | No   | Render content for a specific locale                                   |

**Parameters:**

- `path` (string, required) - Content URL path (e.g., `docs/getting-started`)
- `locale` (string, required for non-default) - BCP 47 locale code (e.g., `en`, `fr`)

**Response:** Rendered HTML page using the active theme template.

**Status Codes:**

| Code | Condition                                         |
| ---- | ------------------------------------------------- |
| 200  | Content found and rendered                        |
| 404  | No published content at the given path and locale |

### Commerce: checkout

Enabled only when `commerce` is configured in `CmsConfig`.

| Method | Path                         | Route Name                      | Auth | Description           |
| ------ | ---------------------------- | ------------------------------- | ---- | --------------------- |
| GET    | `/{locale}/checkout`         | `cms.checkout.show.{locale}`    | No   | Display checkout form |
| POST   | `/{locale}/checkout`         | `cms.checkout.process.{locale}` | No   | Process checkout      |
| GET    | `/{locale}/checkout/success` | `cms.checkout.success.{locale}` | No   | Checkout success page |

### Commerce: digital downloads

| Method | Path                | Route Name     | Auth | Description                                   |
| ------ | ------------------- | -------------- | ---- | --------------------------------------------- |
| GET    | `/download/{token}` | `cms.download` | No   | Download a digital asset using a signed token |

**Parameters:**

- `token` (string, required) - Cryptographically signed download token

**Status Codes:**

| Code | Condition                         |
| ---- | --------------------------------- |
| 200  | Valid token, file download starts |
| 403  | Invalid or expired token          |
| 404  | Asset not found                   |

### Commerce: payment webhooks

| Method | Path                    | Route Name            | Auth      | Description                              |
| ------ | ----------------------- | --------------------- | --------- | ---------------------------------------- |
| POST   | `/webhooks/cms-payment` | `cms.webhook.payment` | Signature | Handle payment gateway webhook callbacks |

## Admin endpoints

All admin endpoints use the prefix `/admin/cms` and require authentication.

### Dashboard

| Method | Path         | Route Name            | Permission           | Description     |
| ------ | ------------ | --------------------- | -------------------- | --------------- |
| GET    | `/admin/cms` | `cms.admin.dashboard` | `cms.dashboard.view` | Admin dashboard |

### Content CRUD

| Method | Path                      | Route Name                 | Permission           | Description         |
| ------ | ------------------------- | -------------------------- | -------------------- | ------------------- |
| GET    | `/admin/cms/content`      | `cms.admin.content.index`  | `cms.content.view`   | List all content    |
| POST   | `/admin/cms/content`      | `cms.admin.content.create` | `cms.content.create` | Create content      |
| GET    | `/admin/cms/content/{id}` | `cms.admin.content.show`   | `cms.content.view`   | Get content details |
| PUT    | `/admin/cms/content/{id}` | `cms.admin.content.update` | `cms.content.edit`   | Update content      |
| DELETE | `/admin/cms/content/{id}` | `cms.admin.content.delete` | `cms.content.delete` | Soft-delete content |

**Create/Update Request Body:**

```json
{
  "content_type": "article",
  "template": null,
  "parent_id": null,
  "sort_order": 0,
  "comment_policy": "inherit",
  "data_classification": "public",
  "translations": {
    "en": {
      "title": "My Article",
      "slug_segment": "my-article",
      "body": "<p>Article content...</p>",
      "excerpt": "Brief summary",
      "meta_title": "My Article | Site Name",
      "meta_description": "Description for search engines"
    }
  }
}
```

### Content translations

| Method | Path                                   | Route Name                          | Permission         | Description            |
| ------ | -------------------------------------- | ----------------------------------- | ------------------ | ---------------------- |
| POST   | `/admin/cms/content/{id}/translations` | `cms.admin.content.add_translation` | `cms.content.edit` | Add locale translation |

### Content workflow

| Method | Path                                    | Route Name                        | Permission                  | Description                 |
| ------ | --------------------------------------- | --------------------------------- | --------------------------- | --------------------------- |
| POST   | `/admin/cms/content/{id}/publish`       | `cms.admin.content.publish`       | `cms.content.publish`       | Publish content             |
| POST   | `/admin/cms/content/{id}/archive`       | `cms.admin.content.archive`       | `cms.content.archive`       | Archive content             |
| POST   | `/admin/cms/content/{id}/schedule`      | `cms.admin.content.schedule`      | `cms.content.publish`       | Schedule future publication |
| POST   | `/admin/cms/content/{id}/submit-review` | `cms.admin.content.submit_review` | `cms.content.submit_review` | Submit for editorial review |

**Schedule Request Body:**

```json
{
  "publish_at": "2026-03-01T09:00:00Z"
}
```

### Content locking

| Method | Path                           | Route Name                 | Permission         | Description       |
| ------ | ------------------------------ | -------------------------- | ------------------ | ----------------- |
| POST   | `/admin/cms/content/{id}/lock` | `cms.admin.content.lock`   | `cms.content.edit` | Acquire edit lock |
| DELETE | `/admin/cms/content/{id}/lock` | `cms.admin.content.unlock` | `cms.content.edit` | Release edit lock |

### Revisions

| Method | Path                                                            | Route Name                    | Permission         | Description            |
| ------ | --------------------------------------------------------------- | ----------------------------- | ------------------ | ---------------------- |
| GET    | `/admin/cms/content/{contentId}/revisions`                      | `cms.admin.revisions.index`   | `cms.content.view` | List content revisions |
| POST   | `/admin/cms/content/{contentId}/revisions/{revisionId}/restore` | `cms.admin.revisions.restore` | `cms.content.edit` | Restore a revision     |

### Editorial reviews

| Method | Path                                    | Route Name                  | Permission            | Description          |
| ------ | --------------------------------------- | --------------------------- | --------------------- | -------------------- |
| GET    | `/admin/cms/reviews`                    | `cms.admin.reviews.index`   | `cms.content.approve` | List pending reviews |
| POST   | `/admin/cms/reviews/{reviewId}/approve` | `cms.admin.reviews.approve` | `cms.content.approve` | Approve a review     |
| POST   | `/admin/cms/reviews/{reviewId}/reject`  | `cms.admin.reviews.reject`  | `cms.content.approve` | Reject a review      |

**Approve/Reject Request Body:**

```json
{
  "reason": "Content meets editorial standards",
  "comment": "Optional feedback note"
}
```

### Taxonomies

| Method | Path                           | Route Name                    | Permission            | Description     |
| ------ | ------------------------------ | ----------------------------- | --------------------- | --------------- |
| GET    | `/admin/cms/taxonomies`        | `cms.admin.taxonomies.index`  | `cms.taxonomy.view`   | List taxonomies |
| POST   | `/admin/cms/taxonomies`        | `cms.admin.taxonomies.create` | `cms.taxonomy.manage` | Create taxonomy |
| GET    | `/admin/cms/taxonomies/{slug}` | `cms.admin.taxonomies.show`   | `cms.taxonomy.view`   | Get taxonomy    |
| PUT    | `/admin/cms/taxonomies/{slug}` | `cms.admin.taxonomies.update` | `cms.taxonomy.manage` | Update taxonomy |
| DELETE | `/admin/cms/taxonomies/{slug}` | `cms.admin.taxonomies.delete` | `cms.taxonomy.manage` | Delete taxonomy |

### Menus

| Method | Path                          | Route Name               | Permission         | Description          |
| ------ | ----------------------------- | ------------------------ | ------------------ | -------------------- |
| GET    | `/admin/cms/menus`            | `cms.admin.menus.index`  | `cms.menus.view`   | List menus           |
| POST   | `/admin/cms/menus`            | `cms.admin.menus.create` | `cms.menus.manage` | Create menu          |
| GET    | `/admin/cms/menus/{location}` | `cms.admin.menus.show`   | `cms.menus.view`   | Get menu by location |
| PUT    | `/admin/cms/menus/{location}` | `cms.admin.menus.update` | `cms.menus.manage` | Update menu          |
| DELETE | `/admin/cms/menus/{location}` | `cms.admin.menus.delete` | `cms.menus.manage` | Delete menu          |

### Custom fields

| Method | Path                                        | Route Name                | Permission                  | Description                  |
| ------ | ------------------------------------------- | ------------------------- | --------------------------- | ---------------------------- |
| GET    | `/admin/cms/fields/{contentType}`           | `cms.admin.fields.index`  | `cms.content.manage_fields` | List fields for content type |
| POST   | `/admin/cms/fields/{contentType}`           | `cms.admin.fields.create` | `cms.content.manage_fields` | Create field definition      |
| PUT    | `/admin/cms/fields/{contentType}/{fieldId}` | `cms.admin.fields.update` | `cms.content.manage_fields` | Update field definition      |
| DELETE | `/admin/cms/fields/{contentType}/{fieldId}` | `cms.admin.fields.delete` | `cms.content.manage_fields` | Delete field definition      |

### Settings

| Method | Path                          | Route Name                  | Permission            | Description           |
| ------ | ----------------------------- | --------------------------- | --------------------- | --------------------- |
| GET    | `/admin/cms/settings/{group}` | `cms.admin.settings.show`   | `cms.settings.view`   | Get settings by group |
| PUT    | `/admin/cms/settings/{group}` | `cms.admin.settings.update` | `cms.settings.manage` | Update settings       |

### Themes

| Method | Path                                | Route Name                    | Permission           | Description                |
| ------ | ----------------------------------- | ----------------------------- | -------------------- | -------------------------- |
| GET    | `/admin/cms/themes`                 | `cms.admin.themes.index`      | `cms.themes.view`    | List installed themes      |
| POST   | `/admin/cms/themes`                 | `cms.admin.themes.install`    | `cms.themes.install` | Install theme from archive |
| POST   | `/admin/cms/themes/{id}/activate`   | `cms.admin.themes.activate`   | `cms.themes.manage`  | Activate theme             |
| POST   | `/admin/cms/themes/{id}/deactivate` | `cms.admin.themes.deactivate` | `cms.themes.manage`  | Deactivate theme           |
| POST   | `/admin/cms/themes/{id}/preview`    | `cms.admin.themes.preview`    | `cms.themes.manage`  | Start preview session      |
| DELETE | `/admin/cms/themes/{id}`            | `cms.admin.themes.delete`     | `cms.themes.delete`  | Delete theme               |

### Plugins

| Method | Path                               | Route Name                          | Permission            | Description                 |
| ------ | ---------------------------------- | ----------------------------------- | --------------------- | --------------------------- |
| GET    | `/admin/cms/plugins`               | `cms.admin.plugins.index`           | `cms.plugins.view`    | List installed plugins      |
| POST   | `/admin/cms/plugins`               | `cms.admin.plugins.install`         | `cms.plugins.install` | Install plugin from archive |
| POST   | `/admin/cms/plugins/{id}/toggle`   | `cms.admin.plugins.toggle`          | `cms.plugins.manage`  | Enable or disable plugin    |
| GET    | `/admin/cms/plugins/{id}/settings` | `cms.admin.plugins.settings`        | `cms.plugins.manage`  | Get plugin settings         |
| PUT    | `/admin/cms/plugins/{id}/settings` | `cms.admin.plugins.update_settings` | `cms.plugins.manage`  | Update plugin settings      |
| DELETE | `/admin/cms/plugins/{id}`          | `cms.admin.plugins.delete`          | `cms.plugins.delete`  | Delete plugin               |

### Users

| Method | Path                              | Route Name                  | Permission         | Description      |
| ------ | --------------------------------- | --------------------------- | ------------------ | ---------------- |
| GET    | `/admin/cms/users`                | `cms.admin.users.index`     | `cms.users.view`   | List CMS users   |
| GET    | `/admin/cms/users/{id}`           | `cms.admin.users.show`      | `cms.users.view`   | Get user details |
| PUT    | `/admin/cms/users/{id}`           | `cms.admin.users.update`    | `cms.users.manage` | Update user      |
| POST   | `/admin/cms/users/{id}/reset-2fa` | `cms.admin.users.reset_2fa` | `cms.users.manage` | Reset user's 2FA |

### Two-factor authentication

| Method | Path                            | Route Name                     | Permission    | Description               |
| ------ | ------------------------------- | ------------------------------ | ------------- | ------------------------- |
| POST   | `/admin/cms/2fa/enroll`         | `cms.admin.2fa.enroll`         | Authenticated | Start 2FA enrollment      |
| POST   | `/admin/cms/2fa/confirm`        | `cms.admin.2fa.confirm`        | Authenticated | Confirm 2FA setup         |
| POST   | `/admin/cms/2fa/verify`         | `cms.admin.2fa.verify`         | Authenticated | Verify 2FA code           |
| POST   | `/admin/cms/2fa/disable`        | `cms.admin.2fa.disable`        | Authenticated | Disable 2FA               |
| POST   | `/admin/cms/2fa/recovery-codes` | `cms.admin.2fa.recovery_codes` | Authenticated | Regenerate recovery codes |

### GDPR tools

| Method | Path                           | Route Name                    | Permission  | Description                             |
| ------ | ------------------------------ | ----------------------------- | ----------- | --------------------------------------- |
| POST   | `/admin/cms/tools/gdpr/export` | `cms.admin.tools.gdpr_export` | `cms.admin` | Export user data (GDPR)                 |
| POST   | `/admin/cms/tools/gdpr/erase`  | `cms.admin.tools.gdpr_erase`  | `cms.admin` | Erase user data (GDPR right to erasure) |

### Commerce: products

| Method | Path                            | Route Name                  | Permission            | Description           |
| ------ | ------------------------------- | --------------------------- | --------------------- | --------------------- |
| GET    | `/admin/cms/products`           | `cms.admin.products.index`  | `cms.products.view`   | List products         |
| GET    | `/admin/cms/products/create`    | `cms.admin.products.create` | `cms.products.create` | Product creation form |
| POST   | `/admin/cms/products`           | `cms.admin.products.store`  | `cms.products.create` | Create product        |
| GET    | `/admin/cms/products/{id}/edit` | `cms.admin.products.edit`   | `cms.products.edit`   | Product edit form     |
| PUT    | `/admin/cms/products/{id}`      | `cms.admin.products.update` | `cms.products.edit`   | Update product        |
| DELETE | `/admin/cms/products/{id}`      | `cms.admin.products.delete` | `cms.products.delete` | Delete product        |

### Commerce: orders

| Method | Path                            | Route Name                | Permission          | Description    |
| ------ | ------------------------------- | ------------------------- | ------------------- | -------------- |
| GET    | `/admin/cms/orders`             | `cms.admin.orders.index`  | `cms.orders.view`   | List orders    |
| GET    | `/admin/cms/orders/{id}`        | `cms.admin.orders.show`   | `cms.orders.view`   | Order details  |
| POST   | `/admin/cms/orders/{id}/refund` | `cms.admin.orders.refund` | `cms.orders.refund` | Process refund |
| GET    | `/admin/cms/orders/export`      | `cms.admin.orders.export` | `cms.orders.export` | Export orders  |

### Commerce: promotions

| Method | Path                              | Route Name                    | Permission              | Description             |
| ------ | --------------------------------- | ----------------------------- | ----------------------- | ----------------------- |
| GET    | `/admin/cms/promotions`           | `cms.admin.promotions.index`  | `cms.promotions.view`   | List promotions         |
| GET    | `/admin/cms/promotions/create`    | `cms.admin.promotions.create` | `cms.promotions.manage` | Promotion creation form |
| POST   | `/admin/cms/promotions`           | `cms.admin.promotions.store`  | `cms.promotions.manage` | Create promotion        |
| GET    | `/admin/cms/promotions/{id}/edit` | `cms.admin.promotions.edit`   | `cms.promotions.manage` | Promotion edit form     |
| PUT    | `/admin/cms/promotions/{id}`      | `cms.admin.promotions.update` | `cms.promotions.manage` | Update promotion        |
| DELETE | `/admin/cms/promotions/{id}`      | `cms.admin.promotions.delete` | `cms.promotions.manage` | Delete promotion        |

### Commerce: digital assets

| Method | Path                             | Route Name                        | Permission                  | Description          |
| ------ | -------------------------------- | --------------------------------- | --------------------------- | -------------------- |
| GET    | `/admin/cms/digital-assets`      | `cms.admin.digital_assets.index`  | `cms.digital_assets.manage` | List digital assets  |
| POST   | `/admin/cms/digital-assets`      | `cms.admin.digital_assets.upload` | `cms.digital_assets.manage` | Upload digital asset |
| DELETE | `/admin/cms/digital-assets/{id}` | `cms.admin.digital_assets.delete` | `cms.digital_assets.manage` | Delete digital asset |

### Commerce: invoices

| Method | Path                                | Route Name                    | Permission              | Description          |
| ------ | ----------------------------------- | ----------------------------- | ----------------------- | -------------------- |
| GET    | `/admin/cms/invoices/{id}`          | `cms.admin.invoices.show`     | `cms.invoices.view`     | View invoice         |
| GET    | `/admin/cms/invoices/{id}/download` | `cms.admin.invoices.download` | `cms.invoices.download` | Download invoice PDF |

### Live CSS editor

| Method | Path                                | Route Name                   | Permission             | Description                  |
| ------ | ----------------------------------- | ---------------------------- | ---------------------- | ---------------------------- |
| GET    | `/admin/cms/live-css`               | `cms.admin.livecss.editor`   | `cms.livecss.view`     | Open Live CSS editor         |
| POST   | `/admin/cms/live-css`               | `cms.admin.livecss.save`     | `cms.livecss.edit`     | Save CSS overrides           |
| POST   | `/admin/cms/live-css/{id}/rollback` | `cms.admin.livecss.rollback` | `cms.livecss.rollback` | Rollback to previous version |
| GET    | `/admin/cms/live-css/history`       | `cms.admin.livecss.history`  | `cms.livecss.view`     | View version history         |

### Export

| Method | Path                         | Route Name                  | Permission   | Description            |
| ------ | ---------------------------- | --------------------------- | ------------ | ---------------------- |
| GET    | `/admin/cms/export`          | `cms.admin.export.form`     | `cms.export` | Export form            |
| POST   | `/admin/cms/export/download` | `cms.admin.export.download` | `cms.export` | Download export bundle |

### Import

| Method | Path                        | Route Name                 | Permission   | Description              |
| ------ | --------------------------- | -------------------------- | ------------ | ------------------------ |
| GET    | `/admin/cms/import`         | `cms.admin.import.form`    | `cms.import` | Import form              |
| POST   | `/admin/cms/import/dry-run` | `cms.admin.import.dry_run` | `cms.import` | Dry-run import (preview) |
| POST   | `/admin/cms/import/execute` | `cms.admin.import.execute` | `cms.import` | Execute import           |

### Site definition import

| Method | Path                             | Route Name                      | Permission   | Description         |
| ------ | -------------------------------- | ------------------------------- | ------------ | ------------------- |
| GET    | `/admin/cms/site-import`         | `cms.admin.site_import.form`    | `cms.import` | Site import form    |
| POST   | `/admin/cms/site-import/dry-run` | `cms.admin.site_import.dry_run` | `cms.import` | Dry-run site import |
| POST   | `/admin/cms/site-import/execute` | `cms.admin.site_import.execute` | `cms.import` | Execute site import |

### Backups

| Method | Path                              | Route Name                  | Permission           | Description         |
| ------ | --------------------------------- | --------------------------- | -------------------- | ------------------- |
| GET    | `/admin/cms/backups`              | `cms.admin.backups.index`   | `cms.backup.create`  | List backups        |
| POST   | `/admin/cms/backups`              | `cms.admin.backups.create`  | `cms.backup.create`  | Create backup       |
| POST   | `/admin/cms/backups/{id}/restore` | `cms.admin.backups.restore` | `cms.backup.restore` | Restore from backup |
| DELETE | `/admin/cms/backups/{id}`         | `cms.admin.backups.delete`  | `cms.backup.create`  | Delete backup       |

## Common response formats

### Success response

```json
{
  "data": { ... },
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 150
  }
}
```

### Error response

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Content title is required",
    "details": {
      "field": "translations.en.title",
      "rule": "required"
    }
  }
}
```

### Common status codes

| Code | Meaning                                         |
| ---- | ----------------------------------------------- |
| 200  | Success                                         |
| 201  | Created                                         |
| 204  | No content (successful delete)                  |
| 400  | Validation error                                |
| 401  | Authentication required                         |
| 403  | Permission denied                               |
| 404  | Resource not found                              |
| 409  | Conflict (e.g., content locked by another user) |
| 422  | Unprocessable entity (business rule violation)  |

## Related documentation

- [Import Format Specification](import-format.md) - JSON schema for site definition imports
- [Architecture Overview](architecture.md) - Route registration and boot sequence
- [Content Type API](content-type-api.md) - Custom field definitions for content endpoints
