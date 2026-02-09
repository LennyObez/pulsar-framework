# Forum Extension

Community discussion, Q&A, and knowledge-sharing extension for Pulsar. Provides threaded discussions, voting, reputation, badges, moderation, tagging, subscriptions, and anti-abuse protection. Designed for regulated, mission-critical domains with full multi-tenancy support.

## Overview

```
ForumExtension
├── Thread (aggregate root: typed discussions, status transitions, pinning)
├── Post (threaded replies, edit windows, solution marking)
├── Category (hierarchical taxonomy, locking)
├── Tag (thread classification labels)
├── Vote (upvote/downvote with direction, thread and post targets)
├── ForumProfile (per-user reputation, activity counts, ban state)
├── Report (thread/post moderation reports with lifecycle)
├── Badge (achievement system with automatic awarding)
├── Subscription (thread follow notifications)
├── Reputation system (point-based levels: Newcomer through Champion)
├── Anti-abuse (link density, similarity detection, honeypot, rate limiting)
├── Admin integration (6 DataResource implementations, dashboard widgets)
└── 18 domain events
```

The forum extension uses the same extension lifecycle as all Pulsar extensions: Register, PreBoot, Boot, PostBoot. Configuration is loaded from `config/forum.php` during PreBoot. Admin resources and CMS widgets are registered conditionally during PostBoot when the admin/CMS extensions are installed.

## Installation & Configuration

### Manifest

The extension is declared in `extensions/forum/pulsar.json` and auto-discovered by the framework's extension pipeline.

### Configuration Reference

All configuration lives in `config/forum.php`. Values map to the `ForumConfig` DTO and its nested configs.

```php
// config/forum.php
return [
    // Pagination
    'threads_per_page' => 25,        // Max threads per listing page
    'posts_per_page' => 20,          // Max posts per thread page

    // Rate limiting
    'post_cooldown_seconds' => 30,   // Min seconds between posts by same user

    // Content rules
    'require_thread_approval' => false, // Moderator approval for new threads
    'allow_guest_viewing' => true,      // Guest read access
    'max_title_length' => 200,          // Thread title character limit
    'max_body_length' => 50_000,        // Post body character limit
    'max_tags_per_thread' => 5,         // Tag limit per thread
    'edit_window_minutes' => 30,        // Edit window (0 = unlimited)

    // Moderation thresholds
    'moderation' => [
        'auto_hide_threshold' => 5,               // Reports to auto-hide
        'notify_threshold' => 3,                   // Reports to notify moderators
        'dismissed_report_retention_days' => 90,   // Dismissed report retention
    ],

    // Reputation system
    'reputation' => [
        'points_per_thread' => 2,           // Points for creating a thread
        'points_per_post' => 1,             // Points for creating a reply
        'points_per_upvote' => 5,           // Points for receiving an upvote
        'points_per_downvote' => -2,        // Points lost on downvote
        'points_per_solution' => 15,        // Points for accepted solution
        'min_reputation_to_downvote' => 50, // Downvote reputation gate
    ],

    // Badge system
    'badges' => [
        'enabled' => true,
        'helpful_upvote_threshold' => 10,          // Upvotes for Helpful badge
        'popular_thread_view_threshold' => 50,     // Views for PopularThread badge
        'solver_accepted_answer_threshold' => 10,  // Solutions for Solver badge
        'bug_hunter_confirmed_threshold' => 5,     // Bug reports for BugHunter badge
        'multilingual_locale_threshold' => 2,      // Locales for Multilingual badge
    ],
];
```

### Config DTOs

| DTO               | Namespace                                  | Purpose                    |
| ------------------ | ------------------------------------------ | -------------------------- |
| `ForumConfig`      | `Pulsar\Extension\Forum\Config`            | Top-level forum settings   |
| `ModerationConfig` | `Pulsar\Extension\Forum\Config`            | Moderation thresholds      |
| `ReputationConfig` | `Pulsar\Extension\Forum\Config`            | Point values and gates     |
| `BadgeConfig`      | `Pulsar\Extension\Forum\Config`            | Badge trigger thresholds   |

All config DTOs are `final readonly` with `fromArray()` factories. Missing keys fall back to sensible defaults.

## Entity Model

### Thread

The primary aggregate root. Represents a discussion thread with typed content, status transitions, and denormalized counters.

| Field            | Type              | Description                               |
| ---------------- | ----------------- | ----------------------------------------- |
| `id`             | `string` (UUIDv7) | Primary key                               |
| `tenantId`       | `?string`         | Multi-tenancy scope                       |
| `categoryId`     | `string`          | FK to Category                            |
| `authorId`       | `string`          | FK to auth user                           |
| `title`          | `string`          | Thread title                              |
| `slug`           | `string`          | URL-safe identifier                       |
| `type`           | `ThreadType`      | Content type enum                         |
| `status`         | `ThreadStatus`    | Lifecycle status                          |
| `isPinned`       | `bool`            | Sticky to top of category                 |
| `isLocked`       | `bool`            | Replies disabled                          |
| `solvedPostId`   | `?string`         | FK to accepted solution post              |
| `replyCount`     | `int`             | Denormalized reply count                  |
| `viewCount`      | `int`             | Denormalized view count                   |
| `voteScore`      | `int`             | Aggregate vote score                      |
| `lastActivityAt` | `?DateTimeImmutable` | Most recent reply or edit              |
| `ipHash`         | `string`          | Hashed IP (anti-abuse, no PII)            |
| `userAgentHash`  | `string`          | Hashed user agent (anti-abuse)            |
| `createdAt`      | `DateTimeImmutable` | Creation timestamp                      |
| `updatedAt`      | `DateTimeImmutable` | Last update timestamp                   |
| `deletedAt`      | `?DateTimeImmutable` | Soft delete timestamp                  |
| `version`        | `int`             | Optimistic concurrency version            |

**Thread types** (`ThreadType` enum):

- `discussion` -- General discussion
- `question` -- Q&A (supports solution marking)
- `bug_report` -- Bug report (supports solution marking)
- `feature_request` -- Feature request
- `showcase` -- Showcase/demo
- `announcement` -- Announcements

**Thread statuses** (`ThreadStatus` enum):

- `open` -- Accepts replies
- `closed` -- No new replies, can reopen
- `locked` -- Moderator-locked

### Post

Represents a reply within a thread. Supports threaded replies via `parentId`, time-limited editing, solution marking, and vote scoring.

| Field                  | Type              | Description                          |
| ---------------------- | ----------------- | ------------------------------------ |
| `id`                   | `string` (UUIDv7) | Primary key                         |
| `tenantId`             | `?string`         | Multi-tenancy scope                  |
| `threadId`             | `string`          | FK to Thread                         |
| `parentId`             | `?string`         | FK to parent Post (threaded replies) |
| `authorId`             | `string`          | FK to auth user                      |
| `body`                 | `string`          | Markdown source                      |
| `bodyHtml`             | `string`          | Pre-rendered sanitized HTML          |
| `isSolution`           | `bool`            | Accepted solution flag               |
| `voteScore`            | `int`             | Aggregate vote score                 |
| `editCount`            | `int`             | Number of edits                      |
| `editedBy`             | `?string`         | FK to last editor                    |
| `editWindowExpiresAt`  | `?DateTimeImmutable` | Edit deadline                     |
| `createdAt`            | `DateTimeImmutable` | Creation timestamp                 |
| `deletedAt`            | `?DateTimeImmutable` | Soft delete timestamp             |
| `version`              | `int`             | Optimistic concurrency version       |

### Category

Hierarchical taxonomy for organizing threads. Supports nesting via `parentId` and ordering via `sortOrder`.

| Field       | Type              | Description                |
| ----------- | ----------------- | -------------------------- |
| `id`        | `string` (UUIDv7) | Primary key               |
| `tenantId`  | `?string`         | Multi-tenancy scope        |
| `parentId`  | `?string`         | FK to parent Category      |
| `slug`      | `string`          | URL-safe identifier        |
| `sortOrder` | `int`             | Sibling ordering           |
| `isLocked`  | `bool`            | Prevents new threads       |
| `createdAt` | `DateTimeImmutable` | Creation timestamp       |
| `updatedAt` | `DateTimeImmutable` | Last update timestamp    |

### Tag

Labels applied to threads for topic classification. Tracks usage count for popularity sorting.

| Field        | Type              | Description           |
| ------------ | ----------------- | --------------------- |
| `id`         | `string` (UUIDv7) | Primary key          |
| `slug`       | `string`          | URL-safe unique slug  |
| `name`       | `string`          | Display name          |
| `description`| `string`          | Purpose description   |
| `usageCount` | `int`             | Denormalized count    |

### ForumProfile

Per-user forum metadata linked to the shared `auth_users` table. Each user has at most one profile per tenant.

| Field             | Type              | Description                    |
| ----------------- | ----------------- | ------------------------------ |
| `id`              | `string` (UUIDv7) | Primary key                   |
| `tenantId`        | `?string`         | Multi-tenancy scope            |
| `userId`          | `string`          | FK to auth_users               |
| `reputationScore` | `int`             | Cumulative reputation          |
| `postCount`       | `int`             | Denormalized post count        |
| `threadCount`     | `int`             | Denormalized thread count      |
| `isBanned`        | `bool`            | Ban flag                       |
| `banReason`       | `?string`         | Ban reason text                |
| `bannedAt`        | `?DateTimeImmutable` | Ban timestamp               |
| `banExpiresAt`    | `?DateTimeImmutable` | Ban expiry (null = permanent)|

### ThreadVote / PostVote

Vote records for threads and posts. Each user can cast one vote per target.

| Field       | Type              | Description              |
| ----------- | ----------------- | ------------------------ |
| `id`        | `string` (UUIDv7) | Primary key             |
| `targetId`  | `string`          | FK to thread or post     |
| `voterId`   | `string`          | FK to auth user          |
| `direction` | `VoteDirection`   | `Up` (+1) or `Down` (-1) |
| `createdAt` | `DateTimeImmutable` | Vote timestamp         |

### ThreadReport / PostReport

Moderation reports with lifecycle state machine.

| Field           | Type              | Description                      |
| --------------- | ----------------- | -------------------------------- |
| `id`            | `string` (UUIDv7) | Primary key                     |
| `tenantId`      | `?string`         | Multi-tenancy scope              |
| `threadId`/`postId` | `string`      | FK to reported content           |
| `reporterId`    | `string`          | FK to reporting user             |
| `reason`        | `string`          | User-provided reason             |
| `status`        | `ReportStatus`    | Lifecycle status                 |
| `moderatorId`   | `?string`         | FK to reviewing moderator        |
| `moderatorNote` | `?string`         | Resolution note                  |
| `createdAt`     | `DateTimeImmutable` | Report timestamp               |
| `reviewedAt`    | `?DateTimeImmutable` | Review timestamp              |

### UserBadge

Records badges awarded to users.

| Field       | Type              | Description           |
| ----------- | ----------------- | --------------------- |
| `id`        | `string` (UUIDv7) | Primary key          |
| `userId`    | `string`          | FK to auth user       |
| `badge`     | `Badge`           | Badge enum value      |
| `awardedAt` | `DateTimeImmutable` | Award timestamp     |

### ThreadSubscription

Thread follow records for notification delivery.

| Field          | Type              | Description           |
| -------------- | ----------------- | --------------------- |
| `id`           | `string` (UUIDv7) | Primary key          |
| `threadId`     | `string`          | FK to Thread          |
| `userId`       | `string`          | FK to auth user       |
| `subscribedAt` | `DateTimeImmutable` | Subscription time   |

## API Reference

All REST endpoints are prefixed with `/api/v1/forum`. Authentication is required for write operations; guest viewing is configurable.

### Categories

| Method | Path                          | Route Name                    | Description       |
| ------ | ----------------------------- | ----------------------------- | ----------------- |
| GET    | `/api/v1/forum/categories`    | `forum.api.categories.index`  | List categories   |
| GET    | `/api/v1/forum/categories/{id}` | `forum.api.categories.show` | View category     |

### Threads

| Method | Path                            | Route Name                    | Description      |
| ------ | ------------------------------- | ----------------------------- | ---------------- |
| GET    | `/api/v1/forum/threads`         | `forum.api.threads.index`     | List threads     |
| POST   | `/api/v1/forum/threads`         | `forum.api.threads.create`    | Create thread    |
| GET    | `/api/v1/forum/threads/{id}`    | `forum.api.threads.show`      | View thread      |
| PUT    | `/api/v1/forum/threads/{id}`    | `forum.api.threads.update`    | Update thread    |
| DELETE | `/api/v1/forum/threads/{id}`    | `forum.api.threads.delete`    | Delete thread    |

### Posts

| Method | Path                                        | Route Name                     | Description       |
| ------ | ------------------------------------------- | ------------------------------ | ----------------- |
| GET    | `/api/v1/forum/threads/{threadId}/posts`    | `forum.api.posts.index`        | List posts        |
| POST   | `/api/v1/forum/threads/{threadId}/posts`    | `forum.api.posts.create`       | Create post       |
| PUT    | `/api/v1/forum/posts/{id}`                  | `forum.api.posts.update`       | Update post       |
| DELETE | `/api/v1/forum/posts/{id}`                  | `forum.api.posts.delete`       | Delete post       |
| POST   | `/api/v1/forum/posts/{id}/solution`         | `forum.api.posts.mark_solution`| Mark as solution  |

### Votes

| Method | Path                                   | Route Name                        | Description        |
| ------ | -------------------------------------- | --------------------------------- | ------------------ |
| POST   | `/api/v1/forum/threads/{id}/vote`      | `forum.api.threads.vote`          | Vote on thread     |
| DELETE | `/api/v1/forum/threads/{id}/vote`      | `forum.api.threads.vote.remove`   | Remove thread vote |
| POST   | `/api/v1/forum/posts/{id}/vote`        | `forum.api.posts.vote`            | Vote on post       |
| DELETE | `/api/v1/forum/posts/{id}/vote`        | `forum.api.posts.vote.remove`     | Remove post vote   |

### Tags

| Method | Path                           | Route Name                | Description |
| ------ | ------------------------------ | ------------------------- | ----------- |
| GET    | `/api/v1/forum/tags`           | `forum.api.tags.index`    | List tags   |
| GET    | `/api/v1/forum/tags/{slug}`    | `forum.api.tags.show`     | View tag    |

### Reports

| Method | Path                                   | Route Name                   | Description     |
| ------ | -------------------------------------- | ---------------------------- | --------------- |
| POST   | `/api/v1/forum/threads/{id}/report`    | `forum.api.threads.report`   | Report thread   |
| POST   | `/api/v1/forum/posts/{id}/report`      | `forum.api.posts.report`     | Report post     |

### Profiles

| Method | Path                                | Route Name                  | Description    |
| ------ | ----------------------------------- | --------------------------- | -------------- |
| GET    | `/api/v1/forum/profiles/{userId}`   | `forum.api.profiles.show`   | View profile   |

### Search

| Method | Path                        | Route Name              | Description       |
| ------ | --------------------------- | ----------------------- | ----------------- |
| GET    | `/api/v1/forum/search`      | `forum.api.search`      | Full-text search  |

### Moderation API

| Method | Path                                              | Route Name                             | Description        |
| ------ | ------------------------------------------------- | -------------------------------------- | ------------------ |
| GET    | `/api/v1/forum/moderation/reports`                | `forum.api.moderation.reports`         | List pending       |
| POST   | `/api/v1/forum/moderation/reports/{id}/resolve`   | `forum.api.moderation.reports.resolve` | Resolve report     |

## Admin Integration

### Admin Panel Routes

The forum registers admin routes under `/admin/forum` for server-rendered management pages:

- **Dashboard**: `GET /admin/forum`
- **Categories**: CRUD at `/admin/forum/categories`
- **Threads**: List, view, update, delete, lock/unlock, pin/unpin at `/admin/forum/threads`
- **Posts**: List, view, delete at `/admin/forum/posts`
- **Moderation**: Report queue, resolve, ban/unban at `/admin/forum/moderation`
- **Tags**: CRUD at `/admin/forum/tags`
- **Users**: List and view at `/admin/forum/users`
- **Badges**: List, award, revoke at `/admin/forum/badges`
- **Settings**: View and update at `/admin/forum/settings`

### Admin DataResource Implementations

When the `pulsar/admin` extension is installed, the forum registers six `DataResourceInterface` implementations via `AdminGateway::registerResource()`:

| Resource               | Table              | Operations                  | Bulk Actions                    |
| ---------------------- | ------------------ | --------------------------- | ------------------------------- |
| `ForumThreadResource`  | `forum_threads`    | List, View, Update, Delete  | lock, unlock, pin, unpin, delete |
| `ForumPostResource`    | `forum_posts`      | List, View, Delete          | delete                          |
| `ForumCategoryResource`| `forum_categories` | List, View, Create, Update, Delete | lock, unlock              |
| `ForumTagResource`     | `forum_tags`       | List, View, Create, Update, Delete | (none)                   |
| `ForumProfileResource` | `forum_profiles`   | List, View, Update          | ban, unban                       |
| `ForumReportResource`  | `forum_reports`    | List, View, Update          | dismiss, action                  |

### Dashboard Widgets

- **Admin widget** (`ForumDashboardWidget`): Implements `WidgetInterface` with thread count, post count, pending reports, and active users in the last 24 hours.
- **CMS widget** (`ForumCmsDashboardWidget`): Implements `DashboardWidgetInterface` with the same metrics, rendered via the `forum/dashboard-widget` template.

Both widgets use `ConnectionInterface` for efficient aggregate COUNT queries without loading entity collections.

## Moderation

### Report Lifecycle

Reports follow a state machine with four statuses:

```
Pending -> UnderReview -> Actioned
                       -> Dismissed
Pending -> Dismissed
```

- `Pending`: Initial state when a user submits a report
- `UnderReview`: Moderator is investigating
- `Actioned`: Content was moderated (removed, edited, user warned)
- `Dismissed`: Report was not actionable

Terminal states (`Actioned`, `Dismissed`) cannot transition further.

### Auto-Moderation Thresholds

| Threshold                          | Default | Behavior                              |
| ---------------------------------- | ------- | ------------------------------------- |
| `moderation.notify_threshold`      | 3       | Notify moderators after N reports     |
| `moderation.auto_hide_threshold`   | 5       | Auto-hide content after N reports     |
| `moderation.dismissed_report_retention_days` | 90 | Days to retain dismissed reports |

### Ban Management

Forum profiles support temporary and permanent bans:

- **Ban**: Sets `isBanned = true` with reason and optional expiry
- **Unban**: Clears ban state (reason, timestamps)
- **Expiry check**: `ForumProfile::isBanExpired()` compares current time against `banExpiresAt`
- Bans can be managed via the admin panel (`/admin/forum/moderation/users/{id}/ban`)

## Reputation & Badges

### Point Values

| Action               | Points | Config Key                   |
| -------------------- | ------ | ---------------------------- |
| Create thread        | +2     | `reputation.points_per_thread`   |
| Create post/reply    | +1     | `reputation.points_per_post`     |
| Receive upvote       | +5     | `reputation.points_per_upvote`   |
| Receive downvote     | -2     | `reputation.points_per_downvote` |
| Post marked solution | +15    | `reputation.points_per_solution` |

Downvoting requires a minimum reputation of 50 (configurable via `reputation.min_reputation_to_downvote`).

### Reputation Levels

Levels are derived from cumulative score via `ReputationLevel::fromScore()`:

| Level       | Minimum Score |
| ----------- | ------------- |
| Newcomer    | 0             |
| Contributor | 10            |
| Regular     | 50            |
| Trusted     | 100           |
| Veteran     | 250           |
| Expert      | 500           |
| Champion    | 1000          |

### Badges

Achievement badges awarded based on configurable thresholds:

| Badge          | Trigger                                  | Config Key                                   |
| -------------- | ---------------------------------------- | -------------------------------------------- |
| `first_post`   | Created first forum post                 | (automatic)                                  |
| `first_answer` | Answered a question for the first time   | (automatic)                                  |
| `helpful`      | Received N upvotes on answers            | `badges.helpful_upvote_threshold` (10)       |
| `popular_thread` | Thread reached N views                 | `badges.popular_thread_view_threshold` (50)  |
| `solver`       | Had N answers marked as solution         | `badges.solver_accepted_answer_threshold` (10)|
| `bug_hunter`   | Reported N confirmed bugs               | `badges.bug_hunter_confirmed_threshold` (5)  |
| `contributor`  | Reached Contributor reputation level     | (automatic at 10 rep)                        |
| `multilingual` | Posted in N distinct locales             | `badges.multilingual_locale_threshold` (2)   |

The badge system can be disabled entirely via `badges.enabled = false`.

## Anti-Abuse

The forum implements layered anti-abuse protections:

### Rate Limiting

- **Post cooldown**: Minimum seconds between consecutive posts by the same user (`post_cooldown_seconds`, default 30s)
- Prevents rapid-fire posting and spam

### Content Validation

- **Max title length**: 200 characters (configurable)
- **Max body length**: 50,000 characters (configurable)
- **Max tags per thread**: 5 (configurable)

### IP & User Agent Hashing

All threads and posts store hashed IP addresses and user agent strings:

- One-way hashing prevents PII retention while enabling abuse pattern detection
- Stored as `ipHash` and `userAgentHash` fields
- Used for detecting coordinated abuse across accounts

### Edit Windows

Posts have a configurable edit window (`edit_window_minutes`, default 30 minutes) after which the author can no longer edit. This prevents stealth editing of content after it has been voted on or replied to. Set to 0 for unlimited editing.

### Link Density & Similarity Detection

The anti-abuse system includes heuristic checks for:

- Excessive link density in post content (spam indicator)
- Content similarity detection across recent posts (duplicate/spam prevention)
- Honeypot fields to detect automated submissions

## Events

The forum dispatches 18 domain events. All events are `#[Api(since: '1.0.0')]` readonly classes.

### Thread Events

| Event            | Payload                                                    |
| ---------------- | ---------------------------------------------------------- |
| `ThreadCreated`  | `threadId`, `categoryId`, `authorId`, `title`, `type`, `?tenantId` |
| `ThreadDeleted`  | `threadId`, `deletedBy`, `?tenantId`                       |
| `ThreadLocked`   | `threadId`, `lockedBy`, `?tenantId`                        |
| `ThreadUnlocked` | `threadId`, `unlockedBy`, `?tenantId`                      |
| `ThreadPinned`   | `threadId`, `pinnedBy`, `?tenantId`                        |
| `ThreadUnpinned` | `threadId`, `unpinnedBy`, `?tenantId`                      |

### Post Events

| Event                  | Payload                                                     |
| ---------------------- | ----------------------------------------------------------- |
| `PostCreated`          | `postId`, `threadId`, `authorId`, `?tenantId`, `authorDisplayName` |
| `PostEdited`           | `postId`, `threadId`, `editedBy`, `?tenantId`               |
| `PostDeleted`          | `postId`, `threadId`, `deletedBy`, `?tenantId`              |
| `PostAcceptedAsSolution` | `postId`, `threadId`, `postAuthorId`, `acceptedBy`, `?tenantId` |

### Vote Events

| Event         | Payload                                                                   |
| ------------- | ------------------------------------------------------------------------- |
| `VoteCast`    | `voteId`, `targetType`, `targetId`, `voterId`, `direction`, `targetAuthorId`, `?tenantId` |
| `VoteRemoved` | `voteId`, `targetType`, `targetId`, `voterId`, `previousDirection`, `targetAuthorId`, `?tenantId` |

### Report Events

| Event            | Payload                                                                 |
| ---------------- | ----------------------------------------------------------------------- |
| `ReportSubmitted` | `reportId`, `targetType`, `targetId`, `reporterId`, `reason`, `?tenantId` |
| `ReportResolved` | `reportId`, `targetType`, `targetId`, `moderatorId`, `resolution`, `?tenantId` |

### User Events

| Event              | Payload                                                        |
| ------------------ | -------------------------------------------------------------- |
| `UserBanned`       | `userId`, `bannedBy`, `reason`, `?expiresAt`, `?tenantId`     |
| `UserUnbanned`     | `userId`, `unbannedBy`, `?tenantId`                            |
| `ReputationChanged`| `userId`, `previousScore`, `newScore`, `delta`, `reason`, `?tenantId` |
| `BadgeAwarded`     | `badgeId`, `userId`, `badge`, `?tenantId`                      |

## Multi-Tenancy

All forum entities include an optional `tenantId` field (UUIDv7). When tenancy is enabled:

- Every query is automatically scoped to the current tenant
- Thread, Post, Category, Tag, ForumProfile, Report, Vote, Badge, and Subscription records are all tenant-isolated
- Cross-tenant access is prevented at the repository layer
- When tenancy is disabled, `tenantId` is `null` and scoping is bypassed

This follows the same tenant isolation pattern used across all Pulsar extensions.

## Security

### Authorization

Forum operations are gated by the authorization system:

- **Thread creation**: Requires authenticated user; banned users are rejected
- **Post creation**: Requires authenticated user, thread must be open and not locked, user must not be banned
- **Voting**: Self-voting is prevented; downvoting requires minimum reputation
- **Moderation**: Report resolution, banning, and thread management require moderator permissions
- **Admin**: Admin panel access requires the admin role

### Input Validation

- Thread titles and post bodies are length-validated against configurable limits
- Post body HTML is pre-rendered and sanitized to prevent XSS
- Thread slugs are generated safely for URL embedding
- Report reasons are validated for minimum content

### CSRF Protection

All state-changing operations (POST, PUT, DELETE) are protected by CSRF tokens through the framework's middleware stack.

### Edit Windows

Time-limited editing prevents retroactive content manipulation:

- Authors can only edit posts within the configured edit window (default 30 minutes)
- Edit count and last editor are tracked for audit purposes
- Edits beyond the window throw `ForumException::editWindowExpired()`

## Performance

### Denormalized Counters

The forum uses denormalized counters to avoid expensive COUNT queries on hot paths:

- `Thread.replyCount` -- incremented atomically when posts are created
- `Thread.viewCount` -- incremented atomically on thread view
- `Thread.voteScore` -- updated atomically when votes are cast or removed
- `Post.voteScore` -- updated atomically when votes are cast or removed
- `Post.editCount` -- incremented on each edit
- `Tag.usageCount` -- incremented/decremented when tags are added/removed from threads
- `ForumProfile.postCount` -- incremented on post creation
- `ForumProfile.threadCount` -- incremented on thread creation
- `ForumProfile.reputationScore` -- updated on reputation-granting events

All counter updates use atomic SQL increments to prevent lost updates under concurrency.

### Pagination Caps

- Threads per page: configurable, default 25
- Posts per page: configurable, default 20
- Maximum enforced at the query layer to prevent unbounded result sets

### Soft Deletes

Threads and posts use soft deletes (`deletedAt` timestamp) rather than physical deletion:

- Preserves referential integrity (votes, reports, subscriptions)
- Enables restoration by moderators
- Deleted records are excluded from default queries via `WHERE deleted_at IS NULL`

### Optimistic Concurrency

Threads and posts include a `version` field for optimistic locking, preventing concurrent updates from silently overwriting each other.
