<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Redaction;

use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

use function preg_replace;
use function str_contains;
use function str_replace;
use function strtoupper;

/**
 * Redacts sensitive data from MCP tool results before wire transmission.
 *
 * Applies regex-based pattern matching for common secret formats (env vars,
 * connection strings, bearer tokens) and value-based matching for known secrets
 * collected from the environment.
 */
#[Internal]
final readonly class McpRedactionPipeline implements McpRedactionPipelineInterface
{
    /** @var list<string> Patterns matching sensitive environment variable assignments */
    private const array ENV_VAR_PATTERNS = [
        '/(?:PULSAR_MASTER_KEY|DB_PASSWORD|DB_USERNAME|API_KEY|API_SECRET|APP_KEY|AUTH_TOKEN|JWT_SECRET|REDIS_PASSWORD|MAIL_PASSWORD|AWS_SECRET_ACCESS_KEY)=\S+/i',
    ];

    private const string CONNECTION_STRING_PATTERN = '/:\/\/[^:]+:[^@]+@/';
    private const string BEARER_PATTERN = '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i';
    private const string REDACTED = '[REDACTED]';

    /**
     * @param SensitiveDataScrubber $scrubber Recursive key-based scrubber for structured data
     * @param list<string> $knownSecretValues Known secret values to match exactly
     */
    public function __construct(
        private SensitiveDataScrubber $scrubber,
        private array $knownSecretValues = [],
    ) {}

    public function redact(ToolResult $result): ToolResult
    {
        $structured = $this->scrubber->scrub($result->structuredContent);
        $text = $this->redactString($result->textContent);

        return new ToolResult(
            structuredContent: $structured,
            textContent: $text,
            isError: $result->isError,
            meta: $result->meta,
        );
    }

    public function redactString(string $input): string
    {
        $output = $input;

        foreach (self::ENV_VAR_PATTERNS as $pattern) {
            $output = preg_replace($pattern, self::REDACTED, $output) ?? $output;
        }

        $output = preg_replace(self::CONNECTION_STRING_PATTERN, '://[REDACTED]@', $output) ?? $output;
        $output = preg_replace(self::BEARER_PATTERN, 'Bearer [REDACTED]', $output) ?? $output;

        foreach ($this->knownSecretValues as $secret) {
            if ($secret !== '' && str_contains($output, $secret)) {
                $output = str_replace($secret, self::REDACTED, $output);
            }
        }

        return $output;
    }

    /**
     * Collect environment variable values that look like secrets.
     *
     * Scans environment variables for keys containing common secret-indicating
     * substrings and returns their values for use in value-based redaction.
     *
     * @return list<string>
     */
    public static function collectKnownSecrets(Environment $environment): array
    {
        $secretPatterns = ['_KEY', '_TOKEN', '_SECRET', '_PASSWORD', '_DSN'];
        $secrets = [];

        foreach ($environment->all() as $key => $value) {
            $upperKey = strtoupper($key);

            foreach ($secretPatterns as $pattern) {
                if (str_contains($upperKey, $pattern) && $value !== '') {
                    $secrets[] = $value;

                    break;
                }
            }
        }

        return $secrets;
    }
}
