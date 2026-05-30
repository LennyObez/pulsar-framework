<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;

use function in_array;
use function is_array;
use function is_string;
use function mb_strtolower;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * WAF engine that evaluates HTTP requests against loaded rule sets.
 *
 * Supports OWASP CRS-style paranoia levels (1-4) and short-circuits
 * on the first blocking match for performance.
 * @api
 */
#[Api(since: '1.0.0')]
final class WafEngine
{
    /** @var list<WafRule> */
    private array $rules = [];

    public function __construct(
        private readonly WafConfig $config,
    ) {}

    /**
     * Load rules into the engine.
     *
     * @param list<WafRule> $rules
     */
    public function loadRules(array $rules): void
    {
        foreach ($rules as $rule) {
            $this->rules[] = $rule;
        }
    }

    /**
     * Evaluate a request against all loaded rules.
     *
     * @return list<WafRuleMatch> Matches found (empty = clean request)
     */
    public function evaluate(ServerRequestInterface $request): array
    {
        if (!$this->config->enabled) {
            return [];
        }

        /** @var mixed $clientIp */
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        if (is_string($clientIp) && in_array($clientIp, $this->config->bypassIps, true)) {
            return [];
        }

        $matches = [];

        foreach ($this->rules as $rule) {
            if ($rule->paranoiaLevel > $this->config->paranoiaLevel) {
                continue;
            }

            $ruleMatches = $this->evaluateRule($rule, $request);

            foreach ($ruleMatches as $match) {
                $matches[] = $match;

                // Short-circuit on first block
                if ($rule->action === WafAction::Block) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * Check if any matches contain a blocking action.
     *
     * @param list<WafRuleMatch> $matches
     */
    public function shouldBlock(array $matches): bool
    {
        foreach ($matches as $match) {
            if ($match->rule->action === WafAction::Block) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<WafRuleMatch>
     */
    private function evaluateRule(WafRule $rule, ServerRequestInterface $request): array
    {
        $matches = [];

        foreach ($rule->targets as $target) {
            $values = $this->extractTargetValues($target, $request);

            foreach ($values as $value) {
                if ($this->matchOperator($rule->operator, $rule->pattern, $value)) {
                    $matches[] = new WafRuleMatch($rule, $target, $value);
                }
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function extractTargetValues(WafTarget $target, ServerRequestInterface $request): array
    {
        return match ($target) {
            WafTarget::Args => $this->flattenParams($request->getQueryParams()),
            WafTarget::Body => $this->extractBody($request),
            WafTarget::Headers => $this->flattenHeaders($request),
            WafTarget::Uri => [$request->getUri()->getPath() . '?' . $request->getUri()->getQuery()],
            WafTarget::Cookies => $this->flattenParams($request->getCookieParams()),
            WafTarget::UserAgent => [$request->getHeaderLine('User-Agent')],
            WafTarget::Method => [$request->getMethod()],
        };
    }

    /**
     * @return list<string>
     */
    private function extractBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            return $this->flattenParams($parsed);
        }

        $body = (string) $request->getBody();

        return $body !== '' ? [$body] : [];
    }

    /**
     * @param array<mixed, mixed> $params
     * @return list<string>
     */
    private function flattenParams(array $params): array
    {
        $values = [];

        /** @var mixed $value */
        foreach ($params as $value) {
            if (is_array($value)) {
                foreach ($this->flattenParams($value) as $nested) {
                    $values[] = $nested;
                }
            } else {
                $values[] = (is_string($value) ? $value : '');
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function flattenHeaders(ServerRequestInterface $request): array
    {
        $values = [];

        foreach ($request->getHeaders() as $headerValues) {
            foreach ($headerValues as $v) {
                $values[] = $v;
            }
        }

        return $values;
    }

    private function matchOperator(WafOperator $operator, string $pattern, string $value): bool
    {
        return match ($operator) {
            WafOperator::Contains => str_contains(mb_strtolower($value, 'UTF-8'), mb_strtolower($pattern, 'UTF-8')),
            WafOperator::BeginsWith => str_starts_with(mb_strtolower($value, 'UTF-8'), mb_strtolower($pattern, 'UTF-8')),
            WafOperator::EndsWith => str_ends_with(mb_strtolower($value, 'UTF-8'), mb_strtolower($pattern, 'UTF-8')),
            WafOperator::Equals => mb_strtolower($value, 'UTF-8') === mb_strtolower($pattern, 'UTF-8'),
            WafOperator::Regex => preg_match($pattern, $value) === 1,
            WafOperator::DetectSqli => $this->detectSqli($value),
            WafOperator::DetectXss => $this->detectXss($value),
        };
    }

    private function detectSqli(string $value): bool
    {
        $patterns = [
            '/\bunion\b.*\bselect\b/i',
            '/\bselect\b.*\bfrom\b/i',
            '/\binsert\b.*\binto\b/i',
            '/\bdelete\b.*\bfrom\b/i',
            '/\bupdate\b.*\bset\b/i',
            '/\bdrop\b.*\btable\b/i',
            '/\bexec\b.*\bxp_/i',
            '/;\s*(select|insert|update|delete|drop|alter|create|exec)/i',
            '/--\s*$/m',
            '/\/\*.*\*\//s',
            '/\b(or|and)\b\s+\d+\s*=\s*\d+/i',
            '/\b(or|and)\b\s+[\'\"].*[\'\"]\s*=\s*[\'\"].*[\'\"]/i',
            "/'\s*(or|and)\s+'.*'\s*=\s*'/i",
            '/\bwaitfor\b.*\bdelay\b/i',
            '/\bbenchmark\s*\(/i',
            '/\bsleep\s*\(/i',
            '/\bload_file\s*\(/i',
            '/\binto\s+outfile\b/i',
            '/\binto\s+dumpfile\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function detectXss(string $value): bool
    {
        $patterns = [
            '/<script[\s>]/i',
            '/javascript\s*:/i',
            '/on(error|load|click|mouse|focus|blur|submit|change|key|touch)\s*=/i',
            '/<iframe[\s>]/i',
            '/<object[\s>]/i',
            '/<embed[\s>]/i',
            '/<svg[\s>].*on\w+\s*=/is',
            '/<img[^>]+on\w+\s*=/i',
            '/expression\s*\(/i',
            '/url\s*\(\s*["\']?\s*javascript/i',
            '/<\w+[^>]*\sstyle\s*=\s*["\'][^"\']*expression/i',
            '/data\s*:\s*text\/html/i',
            '/vbscript\s*:/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
