# AI Tooling - MCP Server Extension

## Overview

Pulsar ships an optional MCP (Model Context Protocol) server extension that exposes framework metadata and developer tools to AI assistants over the JSON-RPC 2.0 stdio transport.

**Intended audience**: Developers using AI-assisted IDEs (Claude Code, Cursor, Windsurf) who want their AI agent to understand the Pulsar project structure - routes, bindings, config schemas, commands, and the public API surface - without manually copying context.

**Design philosophy**: Read-heavy, action-cautious. Read tools are always available (individually disablable). Action tools (run tests, run formatter, run static analysis) require explicit allowlisting. The server is disabled by default and intended for local development only.

## Threat model

### 1. Prompt injection via tool output

**Risk**: A malicious or compromised dependency could inject prompt-manipulating text into tool output (e.g., route names, command descriptions, config property names).

**Mitigations**:

- All tool output passes through `McpRedactionPipeline` which scrubs sensitive patterns
- Structured content (`structuredContent`) is preferred over free-text - AI clients consume typed fields rather than parsing prose
- Output size is capped per tool (`max_output_bytes`, default 1 MB) preventing context flooding
- Route handlers are formatted as `Class::method` - no raw file paths or closure source

### 2. Secret exfiltration

**Risk**: Tool output could inadvertently contain secrets (env var values, database passwords, API keys).

**Mitigations**:

- Three-layer sanitization: contributor limits → `SensitiveDataScrubber` at generation → `McpRedactionPipeline` at export
- Container bindings filtered to FQCN-like keys only - service locator keys like `db.password` excluded
- Config schema exposes property names and types only - never instantiated values
- Subprocess output (tests, formatter, analysis) redacted in real-time with streaming pattern matching
- Value-based redaction collects high-risk env var values (`*_KEY`, `*_TOKEN`, `*_SECRET`, `*_PASSWORD`, `*_DSN`) at boot and replaces exact matches in stdout/stderr
- No absolute file paths in any output

### 3. Unauthorized code execution

**Risk**: An AI agent could use action tools to execute arbitrary commands or modify files.

**Mitigations**:

- Action tools are disabled by default - require explicit allowlisting in `tools.allowed_actions`
- All subprocess commands use `proc_open()` with array command (no shell invocation)
- Parameters are strictly validated: `--filter` allows only `[A-Za-z0-9_:.\\\-]`, `--path` rejects `..` and validates with `realpath()` + root confinement
- Binary paths resolved at boot via `realpath()` and verified against expected directories
- Working directory locked to project root
- Subprocess environment forces non-interactive mode (`--no-interaction --no-ansi`, `CI=1`)
- Hard timeout per action (configurable, default 120s)

### 4. Denial of service via action tools

**Risk**: Repeated or concurrent action tool calls could overwhelm the local machine.

**Mitigations**:

- Concurrency cap: max 1 concurrent action tool (configurable `max_concurrent_actions`)
- Action timeout: subprocess killed after deadline
- Output cap: stdout/stderr truncated at `max_output_bytes`

Per-tool rate limiting is **not** part of this mitigation. `MessageHandler`
calls whatever `RateLimiterInterface` the container happens to hold, under a
per-tool key, and that limiter is the application's HTTP limiter with the
application's HTTP budget — see [Rate limiting](#rate-limiting) for what is and
is not enforced. Do not count it as a DoS control until it is one.

## Enabling safely

### Step 1: create config file

```php
// config/mcp.php
return [
    'enabled' => true,
    'client_id' => 'my-editor',
    'tools' => [
        'disabled_read_tools' => [],
        'allowed_actions' => ['pulsar.tests.run', 'pulsar.formatter.run', 'pulsar.analysis.run'],
        'max_output_bytes' => 1_048_576,
        'action_timeout' => 120,
        'commands' => [
            'phpunit' => null,   // auto-resolve from PATH
            'composer' => null,
            'pnpm' => null,
        ],
    ],
    'security' => [
        'path_allowlist' => ['src/**', 'tests/**', 'extensions/**', 'config/**', 'docs/**'],
        'max_concurrent_actions' => 1,
    ],
];
```

`security.rate_limit_per_minute` and `security.tool_rate_limits` are accepted by
`McpSecurityConfig::fromArray()` and read by nothing — writing them down changes
no behaviour. They are omitted from the example above deliberately; see
[Rate limiting](#rate-limiting).

### Step 2: set environment variable

```bash
export MCP_ENABLED=true
```

### Step 3: start the server

```bash
php bin/pulsar mcp:serve
```

The server reads from stdin and writes to stdout using newline-delimited JSON (JSON-RPC 2.0). Configure your AI client to launch this command as an MCP server.

### Step 4: verify

Send an initialize request:

```json
{ "jsonrpc": "2.0", "id": 1, "method": "initialize", "params": { "protocolVersion": "2025-11-25" } }
```

Expected response includes `serverInfo.name: "pulsar-mcp"` and the negotiated protocol version.

## Security architecture

### Environment gating

| Environment | Requirements                     |
| ----------- | -------------------------------- |
| Local       | `MCP_ENABLED=true` + config file |
| Staging     | + `MCP_STAGING_CONFIRM=true`     |
| Production  | + `MCP_PRODUCTION_CONFIRM=true`  |

### Permission model

1. **Read tools**: Always allowed unless individually disabled via `disabled_read_tools`
2. **Action tools**: Require explicit listing in `tools.allowed_actions`
3. **Path validation**: All `--path` parameters validated with `realpath()` + root confinement + glob allowlist

### Rate limiting

There is no MCP-specific rate limit. What exists is this:

- `MessageHandler` resolves `RateLimiterInterface` from the container **if one is
  bound**, and calls `hit()` on it once per `tools/call` under the key
  `mcp:tool:{name}` — so each tool gets its own bucket.
- The framework binds `RateLimiterInterface` only when
  `rate_limiting.enabled` is true in `config/security.php`. With it off, no
  limiter is bound, `MessageHandler` skips the check, and MCP tool calls are
  unlimited.
- The limit and window are that config's `default_limit` and `default_window`
  (60 requests / 60 seconds as shipped) — the application's HTTP throttle
  budget, applied per tool because the key differs, not because MCP configured
  anything.
- `security.rate_limit_per_minute` and `security.tool_rate_limits` **in
  `config/mcp.php`** are parsed into `McpSecurityConfig` and then read by
  nothing. Setting either changes no limit. They are a defect in the extension,
  not a control; this page will describe a per-tool limit once one exists.

A `hit()` that reports the bucket exhausted returns a tool error
(`Rate limited, retry after N seconds`) and audits the call as `Denied`.

### Concurrency control

- Maximum 1 concurrent action tool execution (configurable)
- Excess requests rejected with structured error response

### Parameter validation

| Parameter  | Validation                                           | Enforced by                                     |
| ---------- | ---------------------------------------------------- | ----------------------------------------------- |
| `--filter` | Max 256 chars, `[A-Za-z0-9_:.\\\-]` only             | `RunTestsTool`                                  |
| `--path`   | No `..`, realpath + root confinement, glob allowlist | `RunTestsTool`, `RunFormatterTool`, access gate |
| `type`     | Enum: `php` or `js`                                  | `RunFormatterTool`                              |
| `analyzer` | Enum: `phpstan` or `psalm`                           | `RunAnalysisTool`                               |

`client_id` is **not** validated. `ParamValidator::validateClientId()` exists and
enforces `[A-Za-z0-9_-]{1,64}`, but nothing calls it: the value is taken from
`config/mcp.php` or `MCP_CLIENT_ID` and interpolated straight into the audit
actor as `mcp-client:{client_id}`. Treat the actor field as operator-supplied
text, not as a constrained identifier, when parsing audit output.

### Redaction pipeline

Composes `SensitiveDataScrubber` with MCP-specific patterns:

- Env var values (`PULSAR_MASTER_KEY=...`, `DB_PASSWORD=...`)
- Connection strings (`://user:pass@host`)
- Bearer tokens in command output
- Value-based redaction of known secret env var values
- Truncation-safe: patterns redact `key=` until line end even if value is split at cap boundary

## Dev-only posture

The MCP server is designed for local development. Production and staging environments require explicit confirmation environment variables as a safety net against accidental enablement.

**Why production stays disabled**:

- Introspection exposes internal architecture details (bindings, routes, config schemas)
- Action tools execute subprocesses on the host
- The stdio transport has no authentication layer - security relies on process-level access control
- In production, the framework should serve requests, not expose diagnostic tooling

**Exception flow for staging/production**:

1. Set `MCP_ENABLED=true` in config
2. Set `MCP_STAGING_CONFIRM=true` or `MCP_PRODUCTION_CONFIRM=true`
3. Both must be present - config alone is insufficient
4. Every `tools/call` is audit-logged regardless of environment, when an audit
   logger is bound (see below)

## Audit and monitoring

### Audit trail

`tools/call` is the only audited method. `initialize`, `ping`, `tools/list` and
the notification methods produce no audit entry.

Auditing happens only when the container holds an `AuditLoggerInterface`.
`MessageHandler` takes it as a nullable dependency, so an application that binds
no audit logger runs MCP with no audit trail at all and nothing reports that.

Every audited call is written with the **same** event type — `AuditEvent::DataAccess`.
The scenario is carried by the outcome and the metadata, not by the event:

| Scenario                                    | AuditEvent | AuditOutcome |
| ------------------------------------------- | ---------- | ------------ |
| Tool succeeded                              | DataAccess | Success      |
| Tool returned an error result (`isError`)   | DataAccess | Failure      |
| Permission denied (`assertAllowed` refused) | DataAccess | Denied       |
| Rate limited                                | DataAccess | Denied       |
| Tool threw (`McpException` or any other)    | DataAccess | Error        |
| Concurrency limited                         | DataAccess | Error        |

Concurrency rejection lands in the `Error` row rather than a `Denied` one
because `McpSecurityException::concurrencyLimited()` is thrown from inside the
action tool, after the permission and rate-limit gates have already passed, and
is caught by the generic handler around tool execution. The client is told
`Internal tool execution error`; the specific reason survives only in the audit
entry's `detail`.

**Fields written**:

| Field      | Value                                                       |
| ---------- | ----------------------------------------------------------- |
| `actor`    | `mcp-client:{client_id}`                                    |
| `action`   | `mcp:tools/call:{tool}`                                     |
| `resource` | the tool name                                               |
| `metadata` | `{"detail": "..."}` — `OK` on success, otherwise the reason |

**Metadata is one key.** There is no `tool`, `category`, `params`,
`duration_ms`, `output_bytes` or `client_id` field on the entry — the tool name
reaches the log through `action` and `resource`, the client id through `actor`,
and nothing measures duration or output size. Alerting that needs those must
derive them from the four fields above.

### Reviewing activity

If Studio is enabled, MCP audit entries appear in the Studio dashboard under the
Security Events timeline. Filter by actor prefix `mcp-client:` to isolate MCP
activity — note the `-client` segment; `mcp:` alone matches nothing in the actor
field, and matches every entry's `action`.

### Abuse detection

What the audit trail supports watching for:

- Repeated `Denied` entries — a client calling action tools that are not in
  `allowed_actions`, or hitting the limiter when one is bound
- Repeated `Error` entries whose `detail` reads
  `Maximum concurrent MCP action executions reached` (multiple clients sharing
  one config) or `Path access not allowed` (path probing)
- A burst of `Success` entries on action tools outside working hours

What it does **not** support: tool arguments are not recorded, so a rejected
`--path` shows up as the refusal message and never as the path that was tried;
and neither call duration nor output size is measured, so neither can be
trended. Both need instrumentation the extension does not yet have.

## Incident response

### 1. Disable immediately

```bash
unset MCP_ENABLED
# or set enabled: false in config/mcp.php
```

Kill any running `mcp:serve` process.

### 2. Review audit logs

Check audit entries with actor prefix `mcp-client:` for:

- Which tools were called (`resource`, and the tail of `action`)
- Whether any action tools executed, and with what outcome
- Which calls were refused, and why (`metadata.detail`)

The entries cannot tell you what arguments a call carried, how long it ran, or
how much output it returned — none of that is recorded. For those, fall back to
the process-level evidence: the `mcp:serve` process's own stdout/stderr if it
was captured, and the shell history or CI logs of whatever the action tools
spawned.

### 3. Rotate keys

If secrets may have been exposed:

- Rotate `PULSAR_MASTER_KEY`
- Rotate any application-level API keys, tokens, database passwords
- Invalidate active sessions

### 4. Assess exposure

- Review action tool output for any sensitive data that may have bypassed redaction
- Check subprocess execution history for unexpected commands
- Verify no files were modified outside the path allowlist

## Tool reference

### Read tools

#### `pulsar.api.snapshot`

Returns the public API snapshot (classes marked with `#[Api]`).

- **Category**: Read
- **Parameters**: `class` (string, optional), `namespacePrefix` (string, optional), `cursor` (string, optional), `limit` (integer, optional, default 100)
- **Output**: `{ "classes": { ... }, "totalCount": N, "nextCursor": null }`

#### `pulsar.architecture.map`

Returns the architecture map: registered extensions and container bindings.

- **Category**: Read
- **Parameters**: none
- **Output**: `{ "extensions": [...], "bindings": [...] }`

#### `pulsar.config.schema`

Returns config DTO schemas (property names, types, scrubbed defaults).

- **Category**: Read
- **Parameters**: `name` (string, optional - filter by config name)
- **Output**: `{ "schemas": [...] }`

#### `pulsar.routes.list`

Returns all registered routes with method, path, handler, and middleware.

- **Category**: Read
- **Parameters**: `method` (string, optional), `path` (string, optional), `cursor` (string, optional), `limit` (integer, optional, default 100)
- **Output**: `{ "routes": [...], "totalCount": N, "nextCursor": null }`

#### `pulsar.commands.list`

Returns all registered console commands with arguments and options.

- **Category**: Read
- **Parameters**: `namespace` (string, optional), `cursor` (string, optional), `limit` (integer, optional, default 100)
- **Output**: `{ "commands": [...], "totalCount": N, "nextCursor": null }`

#### `pulsar.container.bindings`

Returns container binding keys (FQCN-like patterns only).

- **Category**: Read
- **Parameters**: `filter` (string, optional - substring match), `cursor` (string, optional), `limit` (integer, optional, default 100)
- **Output**: `{ "bindings": [...], "totalCount": N, "nextCursor": null }`

### Action tools

#### `pulsar.tests.run`

Runs PHPUnit with the project configuration.

- **Category**: Action
- **Parameters**: `filter` (string, optional - test name filter), `path` (string, optional - test file/directory)
- **Output**: `{ "exitCode": 0, "stdout": "...", "stderr": "...", "timedOut": false, "wasCancelled": false, "truncated": false }`

#### `pulsar.formatter.run`

Runs code formatters.

- **Category**: Action
- **Parameters**: `type` (string, required - `php` or `js`), `path` (string, optional)
- **Output**: `{ "exitCode": 0, "stdout": "...", "stderr": "...", "timedOut": false, "wasCancelled": false, "truncated": false }`

#### `pulsar.analysis.run`

Runs static analysis tools.

- **Category**: Action
- **Parameters**: `analyzer` (string, required - `phpstan` or `psalm`)
- **Output**: `{ "exitCode": 0, "stdout": "...", "stderr": "...", "timedOut": false, "wasCancelled": false, "truncated": false }`
