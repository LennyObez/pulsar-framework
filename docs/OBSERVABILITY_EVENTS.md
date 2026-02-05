# Observability Events

Studio collects structured events from the framework runtime. Each event type has a typed payload DTO and is wrapped in an `EventEnvelope` with common metadata.

## Event Types

| Event Type        | Enum Value          | Payload DTO             | Collector                |
| ----------------- | ------------------- | ----------------------- | ------------------------ |
| HTTP Request      | `http.request`      | `HttpRequestPayload`    | `HttpCollector`          |
| HTTP Response     | `http.response`     | `HttpResponsePayload`   | `HttpCollector`          |
| Database Query    | `database.query`    | `DatabaseQueryPayload`  | `InstrumentedConnection` |
| Cache Operation   | `cache.operation`   | `CacheOperationPayload` | (future)                 |
| Job Queued        | `job.queued`        | `JobPayload`            | (future)                 |
| Job Processing    | `job.processing`    | `JobPayload`            | (future)                 |
| Job Completed     | `job.completed`     | `JobPayload`            | (future)                 |
| Job Failed        | `job.failed`        | `JobPayload`            | (future)                 |
| Scheduler Run     | `scheduler.run`     | `SchedulerRunPayload`   | `InstrumentedScheduler`  |
| Outgoing HTTP     | `outgoing.http`     | `OutgoingHttpPayload`   | (future)                 |
| Notification      | `notification`      | `NotificationPayload`   | (future)                 |
| Exception         | `exception`         | `ExceptionPayload`      | `ExceptionCollector`     |
| Log Entry         | `log.entry`         | `LogEntryPayload`       | `LogCollector`           |
| Feature Flag Eval | `feature_flag.eval` | `FeatureFlagPayload`    | `FeatureFlagCollector`   |
| Heartbeat         | `heartbeat`         | (none)                  | (internal)               |

## Event Envelope

Every event is wrapped in an `EventEnvelope` containing:

| Field           | Type           | Description                                        |
| --------------- | -------------- | -------------------------------------------------- |
| `eventId`       | `string`       | Hex-encoded 16-byte random identifier              |
| `eventType`     | `EventType`    | Enum value identifying the event kind              |
| `schemaVersion` | `EventVersion` | Payload schema version for forward compatibility   |
| `timestampUs`   | `int`          | Microsecond-precision Unix timestamp               |
| `requestId`     | `?string`      | HTTP request correlation ID                        |
| `traceId`       | `?string`      | Distributed trace ID                               |
| `spanId`        | `?string`      | Current span ID                                    |
| `jobId`         | `?string`      | Scheduler job correlation ID                       |
| `appEnv`        | `string`       | Application environment (local/staging/production) |
| `hostname`      | `string`       | Server hostname                                    |
| `payload`       | `array`        | Serialized payload DTO                             |
| `payloadHash`   | `string`       | SHA-256 hash of the serialized payload JSON        |

### Canonical Form

For hash chain computation, each envelope produces a canonical string:

```
eventId|eventType|schemaVersion|timestampUs|traceId|payloadHash
```

Null fields are encoded as empty strings. The pipe delimiter never appears in any field value, so no escaping is needed.

## Payload DTOs

### HttpRequestPayload

| Field      | Type     | Description         |
| ---------- | -------- | ------------------- |
| `method`   | `string` | HTTP method         |
| `uri`      | `string` | Request URI         |
| `path`     | `string` | Request path        |
| `headers`  | `array`  | Redacted header map |
| `bodySize` | `int`    | Request body size   |

### HttpResponsePayload

| Field        | Type    | Description            |
| ------------ | ------- | ---------------------- |
| `statusCode` | `int`   | HTTP status code       |
| `headers`    | `array` | Response header map    |
| `bodySize`   | `int`   | Response body size     |
| `durationMs` | `float` | Request duration in ms |

### DatabaseQueryPayload

| Field            | Type      | Description                                       |
| ---------------- | --------- | ------------------------------------------------- |
| `sql`            | `string`  | Normalized SQL (literals stripped)                |
| `sqlFingerprint` | `string`  | SHA-256 hash of normalized SQL                    |
| `sqlRaw`         | `?string` | Raw SQL (only in local mode with `store_raw_sql`) |
| `connection`     | `string`  | Connection name                                   |
| `durationMs`     | `float`   | Query duration in ms                              |
| `rowCount`       | `?int`    | Number of affected/returned rows                  |

SQL normalization strips numeric and string literals, collapses whitespace, and lowercases keywords. The `sqlFingerprint` groups equivalent queries for performance analysis. Raw SQL is only stored in local development with `store_raw_sql: true` explicitly set.

### ExceptionPayload

| Field         | Type     | Description                       |
| ------------- | -------- | --------------------------------- |
| `class`       | `string` | Exception class name              |
| `message`     | `string` | Exception message (redacted)      |
| `code`        | `int`    | Exception code                    |
| `file`        | `string` | File where exception was thrown   |
| `line`        | `int`    | Line number                       |
| `fingerprint` | `string` | Error fingerprint for grouping    |
| `previous`    | `?array` | Previous exception (causal chain) |

### LogEntryPayload

| Field     | Type     | Description                  |
| --------- | -------- | ---------------------------- |
| `level`   | `string` | Log level (emergency..debug) |
| `channel` | `string` | Log channel                  |
| `message` | `string` | Log message (redacted)       |
| `context` | `array`  | Redacted context data        |

### SchedulerRunPayload

| Field        | Type     | Description                   |
| ------------ | -------- | ----------------------------- |
| `jobName`    | `string` | Scheduled job name            |
| `durationMs` | `float`  | Job duration in ms            |
| `outcome`    | `string` | Job outcome (success/failure) |
| `missed`     | `bool`   | Whether the job was overdue   |

### FeatureFlagPayload

| Field    | Type     | Description          |
| -------- | -------- | -------------------- |
| `flag`   | `string` | Feature flag name    |
| `value`  | `mixed`  | Evaluated flag value |
| `reason` | `string` | Evaluation reason    |

### JobPayload

| Field        | Type      | Description               |
| ------------ | --------- | ------------------------- |
| `jobClass`   | `string`  | Job class name            |
| `jobId`      | `string`  | Job identifier            |
| `queue`      | `string`  | Queue name                |
| `durationMs` | `?float`  | Duration (if completed)   |
| `error`      | `?string` | Error message (if failed) |

### CacheOperationPayload

| Field        | Type     | Description                      |
| ------------ | -------- | -------------------------------- |
| `operation`  | `string` | Operation (get/set/delete/flush) |
| `key`        | `string` | Cache key                        |
| `hit`        | `?bool`  | Cache hit/miss                   |
| `durationMs` | `float`  | Operation duration               |

### OutgoingHttpPayload

| Field        | Type     | Description           |
| ------------ | -------- | --------------------- |
| `method`     | `string` | HTTP method           |
| `url`        | `string` | Target URL (redacted) |
| `statusCode` | `?int`   | Response status code  |
| `durationMs` | `float`  | Request duration      |

### NotificationPayload

| Field     | Type     | Description          |
| --------- | -------- | -------------------- |
| `channel` | `string` | Notification channel |
| `type`    | `string` | Notification type    |
| `status`  | `string` | Delivery status      |

### TenancyPayload

| Field        | Type      | Description        |
| ------------ | --------- | ------------------ |
| `tenantId`   | `string`  | Tenant identifier  |
| `action`     | `string`  | Tenancy action     |
| `databaseId` | `?string` | Tenant database ID |

## Redaction

All payloads pass through the `RedactionPipeline` before storage. The `DefaultRedactionPolicy` scrubs:

- Bearer tokens and API keys in headers
- Password fields in request bodies
- Database connection strings (DSNs)
- Session tokens and cookies
- Inline SQL literals (via `SqlNormalizer`)

Redacted values are replaced with `[REDACTED]`. Redaction is applied before hashing, so `payloadHash` always reflects the redacted content.

## Correlation

Events are correlated using IDs from `CorrelationContext`:

- `requestId`: Groups all events from a single HTTP request
- `traceId`: Links to distributed tracing spans
- `jobId`: Groups all events from a single scheduler job

When a job runs within an HTTP request, events carry both `requestId` and `jobId`. The timeline view reconstructs the full execution flow from these correlation IDs.

Tenant identity is resolved at ingest time via `TenantContext::tryGet()` and stored as a hashed value (`tenant_hash`), not as part of the correlation context.
