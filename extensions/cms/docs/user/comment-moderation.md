# Comment moderation guide

This guide covers the Pulsar CMS comment system, including moderation workflows, anti-abuse protections, rate limiting, and comment policies.

## Overview

Pulsar CMS includes a full-featured commenting system with moderation controls designed for regulated environments. All comments pass through sanitization and anti-abuse checks before reaching moderators.

## Comment lifecycle

```
Visitor submits comment
    |
    v
Anti-abuse checks (honeypot, rate limit, heuristics)
    |
    v
HTML sanitization (strict SafeHtmlPolicy comment subset)
    |
    v
Pending moderation queue
    |
    +--> Approved  (visible to public)
    +--> Rejected  (hidden, preserved for audit)
    +--> Spam      (hidden, preserved for audit)
```

## Moderation statuses

| Status       | Description               | Publicly Visible |
| ------------ | ------------------------- | ---------------- |
| **Pending**  | Awaiting moderator review | No               |
| **Approved** | Accepted by a moderator   | Yes              |
| **Rejected** | Declined by a moderator   | No               |
| **Spam**     | Flagged as spam           | No               |

Moderation decisions are final. Once a comment transitions from Pending to any other status, no further transitions are allowed. This immutability ensures a clear audit trail.

## Moderating comments

### Via admin panel

<!-- Screenshot: Comment moderation queue -->

1. Navigate to **Admin > CMS > Content > {id}** and open the comments section, or view comments through the admin comment controller.
2. The moderation queue shows all pending comments with:

- Author name and email (if provided)
- Comment body (sanitized)
- Associated content item
- Submission timestamp
- Parent comment (for replies)

3. For each pending comment, choose an action:

- **Approve**: Makes the comment publicly visible
- **Reject**: Hides the comment with an optional reason
- **Mark as Spam**: Flags the comment as spam

### Audit logging

Every moderation action is recorded in the audit log:

- **Event**: `cms.comment.approved`, `cms.comment.rejected`, or `cms.comment.spam`
- **Actor**: The moderator's user ID
- **Subject**: `comment:{id}`
- **Evidence**: Previous status, new status, content ID

## Comment policies

Each content item has a comment policy that controls whether comments are accepted:

| Policy    | Behavior                              |
| --------- | ------------------------------------- |
| `inherit` | Uses the site-wide default setting    |
| `open`    | Comments are accepted on this content |
| `closed`  | No new comments are accepted          |

Set the comment policy when creating or editing content at **Admin > CMS > Content > {id}**.

### Site-wide setting

The global comment toggle is controlled in `config/cms.php`:

```php
'comments' => [
    'enabled' => true,  // Master switch for the comment system
],
```

When `enabled` is `false`, no comments are accepted anywhere on the site, regardless of individual content policies.

## Guest comments

By default, unauthenticated visitors can submit comments:

```php
'comments' => [
    'guest_comments_allowed' => true,
    'require_email' => false,  // Whether guests must provide an email
],
```

When `guest_comments_allowed` is `false`, only authenticated users can comment.

## Auto-approval

Authenticated users' comments can be auto-approved:

```php
'comments' => [
    'auto_approve_authenticated' => false,
],
```

When enabled, comments from logged-in users bypass the moderation queue and are immediately visible. This is disabled by default for regulated environments.

## Comment body rules

### Character limit

```php
'comments' => [
    'max_body_length' => 10000,  // Maximum comment length in characters
],
```

### Link limit

```php
'comments' => [
    'max_links_per_comment' => 3,  // Maximum hyperlinks per comment
],
```

Comments exceeding the link limit are flagged for review. Excessive links are a common spam indicator.

### HTML sanitization

Comment bodies are sanitized using the **strict comment subset** of the SafeHtmlPolicy:

| Allowed Elements | Attributes             |
| ---------------- | ---------------------- |
| `p`              | --                     |
| `br`             | --                     |
| `strong`         | --                     |
| `em`             | --                     |
| `a`              | `href`, `rel`, `title` |
| `code`           | --                     |
| `blockquote`     | --                     |
| `pre`            | --                     |

All other HTML elements are unwrapped (their text content is preserved). Links receive `rel="noopener noreferrer"` for security.

### Nesting depth

```php
'comments' => [
    'max_nesting_depth' => 3,  // Maximum reply depth
],
```

Replies beyond the maximum nesting depth are attached to the deepest allowed parent.

## Edit window

Authors can edit their own comments within a configurable time window:

```php
'comments' => [
    'edit_window_minutes' => 15,
],
```

After the window expires, comments are immutable. Edits within the window are audit-logged.

## Anti-abuse protections

Pulsar CMS includes multiple layers of automated anti-abuse measures.

### Rate limiting

Rate limits prevent comment flooding:

```php
'comments' => [
    'rate_limit_per_minute' => 5,   // Max comments per minute per user/IP
    'rate_limit_per_hour' => 30,     // Max comments per hour per user/IP
],
```

When a user exceeds the rate limit, subsequent comment submissions are rejected with a `429 Too Many Requests` response. The `CommentRateLimitMiddleware` enforces these limits.

### Honeypot field

The honeypot technique detects automated bots:

```php
'comments' => [
    'honeypot_field_name' => 'website_url',
],
```

How it works:

1. The comment form includes a hidden field (configured name, default: `website_url`).
2. The field is hidden via CSS so human users never see or fill it.
3. Bots that fill in all form fields populate the honeypot.
4. If the honeypot field contains a value, the submission is silently rejected.

The honeypot trigger is audit-logged as a security event:

- **Event**: `cms.comment.honeypot_triggered`
- **Type**: Security Event
- **Outcome**: Denied

### Anti-abuse heuristics

The `AntiAbuseHeuristics` engine applies additional checks:

- Repeated identical comment body detection
- Excessive uppercase content detection
- Known spam patterns in comment text
- Abnormally fast submission timing (faster than a human could type)

Comments flagged by heuristics are placed in the pending queue for manual review.

## Comment events

The comment system dispatches events that can be consumed by other parts of the application:

| Event              | When                                                      |
| ------------------ | --------------------------------------------------------- |
| `CommentSubmitted` | A new comment is submitted (after anti-abuse checks pass) |
| `CommentModerated` | A moderator approves, rejects, or flags a comment         |

These events can trigger notifications, webhooks, or custom integrations.

## Permissions

| Permission              | Role         | Description                       |
| ----------------------- | ------------ | --------------------------------- |
| `cms.comments.view`     | Contributor+ | View comments on content          |
| `cms.comments.moderate` | Editor+      | Approve, reject, or flag comments |

## Configuration reference

| Key                                   | Type   | Default         | Description                            |
| ------------------------------------- | ------ | --------------- | -------------------------------------- |
| `comments.enabled`                    | bool   | `true`          | Master switch for comments             |
| `comments.auto_approve_authenticated` | bool   | `false`         | Auto-approve logged-in users' comments |
| `comments.edit_window_minutes`        | int    | `15`            | Edit window in minutes                 |
| `comments.max_nesting_depth`          | int    | `3`             | Maximum reply nesting                  |
| `comments.rate_limit_per_minute`      | int    | `5`             | Per-user/IP rate limit (per minute)    |
| `comments.rate_limit_per_hour`        | int    | `30`            | Per-user/IP rate limit (per hour)      |
| `comments.guest_comments_allowed`     | bool   | `true`          | Allow unauthenticated comments         |
| `comments.require_email`              | bool   | `false`         | Require email from guests              |
| `comments.max_body_length`            | int    | `10000`         | Maximum comment length                 |
| `comments.max_links_per_comment`      | int    | `3`             | Maximum links per comment              |
| `comments.honeypot_field_name`        | string | `'website_url'` | Hidden honeypot field name             |

## Next steps

- [Content Management Guide](content-management.md) - Setting comment policies per content
- [Security Model](../security/security-model.md) - Comment-related permissions
- [Audit Events Reference](../security/audit-events.md) - Comment audit trail
