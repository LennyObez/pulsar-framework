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
- Rate limiting: default 60 requests/minute per tool, with per-tool overrides
- Action timeout: subprocess killed after deadline
- Output cap: stdout/stderr truncated at `max_output_bytes`

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
        'rate_limit_per_minute' => 60,
        'tool_rate_limits' => [],
        'max_concurrent_actions' => 1,
    ],
];
```

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

- Global default: 60 requests/minute per tool
- Per-tool overrides via `security.tool_rate_limits`
- Uses framework `RateLimiterInterface` with key format `mcp:tool:{name}`

### Concurrency control

- Maximum 1 concurrent action tool execution (configurable)
- Excess requests rejected with structured error response

### Parameter validation

| Parameter   | Validation                                           |
| ----------- | ---------------------------------------------------- |
| `client_id` | `[A-Za-z0-9_-]{1,64}`                                |
| `--filter`  | Max 256 chars, `[A-Za-z0-9_:.\\\-]` only             |
| `--path`    | No `..`, realpath + root confinement, glob allowlist |
| `type`      | Enum: `php` or `js`                                  |
| `analyzer`  | Enum: `phpstan` or `psalm`                           |

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
4. All MCP activity is audit-logged regardless of environment

## Audit and monitoring

### Audit trail

Every `tools/call` invocation is logged via `AuditLoggerInterface`:

| Scenario            | AuditEvent    | AuditOutcome |
| ------------------- | ------------- | ------------ |
| Read tool success   | DataAccess    | Success      |
| Action tool success | SystemEvent   | Success      |
| Permission denied   | Authorization | Denied       |
| Rate limited        | SecurityEvent | Denied       |
| Concurrency limited | SecurityEvent | Denied       |
| Tool error          | SystemEvent   | Error        |
| Tool cancelled      | SystemEvent   | Failure      |

**Metadata fields**: `tool`, `category`, `params` (redacted), `duration_ms`, `output_bytes`, `client_id`

**Actor format**: `mcp:{clientId}`

### Reviewing activity

If Studio is enabled, MCP audit entries appear in the Studio dashboard under the Security Events timeline. Filter by actor prefix `mcp:` to isolate MCP activity.

### Abuse detection

Watch for:

- High rate limit hit counts on action tools
- Unusual `--path` parameters (even though traversal is blocked, attempts indicate probing)
- Repeated permission-denied entries for action tools not in the allowlist
- Concurrent action rejections (may indicate multiple clients sharing a config)

## Incident response

### 1. Disable immediately

```bash
unset MCP_ENABLED
# or set enabled: false in config/mcp.php
```

Kill any running `mcp:serve` process.

### 2. Review audit logs

Check audit entries with actor prefix `mcp:` for:

- Which tools were called
- What parameters were passed
- Whether any action tools executed
- Duration and output sizes (unusually large may indicate data exfiltration attempts)

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
