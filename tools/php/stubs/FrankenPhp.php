<?php

/**
 * The one FrankenPHP function jetbrains/phpstorm-stubs does not declare.
 *
 * Everything else the FrankenPHP runtime exposes — headers_send(),
 * frankenphp_handle_request(), frankenphp_finish_request(),
 * frankenphp_request_headers(), frankenphp_response_headers(), mercure_publish(),
 * frankenphp_log() — now comes from vendor/jetbrains/phpstorm-stubs/frankenphp/,
 * which tracks the runtime upstream. This file used to restate
 * frankenphp_handle_request() too: exactly the kind of hand-maintained copy that
 * drifts from the thing it describes while looking authoritative.
 *
 * frankenphp_early_hints() is absent from that upstream set. It is declared here so
 * src/Runtime/FrankenPhpRuntime::earlyHints() can be analysed, and its call site is
 * guarded by function_exists(). Do not read this declaration as evidence that the
 * function exists: if it does not, that guard makes HTTP 103 Early Hints a permanent
 * silent no-op, and the supported FrankenPHP mechanism is headers_send(103). That
 * question is tracked separately.
 *
 * Analysis-only, never loaded at runtime.
 */

/**
 * Send HTTP 103 Early Hints for the given headers.
 *
 * @param array<string|int, string> $headers
 */
function frankenphp_early_hints(array $headers): void {}
