<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use Pulsar\Api\Api;

/**
 * Built-in OWASP Core Rule Set (CRS) inspired rules.
 *
 * Provides a baseline ruleset covering common web attack categories.
 * Rule IDs follow CRS convention: 9xxxxx for SQLi, 941xxx for XSS, etc.
 * @api
 */
#[Api(since: '1.0.0')]
final class OwaspCoreRuleSet
{
    /**
     * @return list<WafRule>
     */
    public static function rules(): array
    {
        return [
            ...self::sqliRules(),
            ...self::xssRules(),
            ...self::pathTraversalRules(),
            ...self::commandInjectionRules(),
            ...self::rfiLfiRules(),
            ...self::protocolViolationRules(),
        ];
    }

    /**
     * @return list<WafRule>
     */
    private static function sqliRules(): array
    {
        return [
            new WafRule(
                id: '942100',
                message: 'SQL Injection Attack Detected via libinjection',
                targets: [WafTarget::Args, WafTarget::Body, WafTarget::Cookies],
                operator: WafOperator::DetectSqli,
                pattern: '',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '942110',
                message: 'SQL Injection: UNION SELECT detected',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/\bunion\b\s+(all\s+)?select\b/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '942120',
                message: 'SQL Injection: comment sequence (--) detected',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/\'\s*--/',
                action: WafAction::Block,
                severity: WafSeverity::Warning,
                paranoiaLevel: 2,
            ),
            new WafRule(
                id: '942130',
                message: 'SQL Injection: tautology (1=1) detected',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/\b(or|and)\b\s+\d+\s*=\s*\d+/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '942140',
                message: 'SQL Injection: time-based blind (SLEEP/BENCHMARK)',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/\b(sleep|benchmark|waitfor\s+delay)\s*\(/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
        ];
    }

    /**
     * @return list<WafRule>
     */
    private static function xssRules(): array
    {
        return [
            new WafRule(
                id: '941100',
                message: 'XSS Attack Detected: script tag',
                targets: [WafTarget::Args, WafTarget::Body, WafTarget::Headers],
                operator: WafOperator::DetectXss,
                pattern: '',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '941110',
                message: 'XSS: javascript: URI scheme',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/javascript\s*:/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '941120',
                message: 'XSS: event handler attribute',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/on(error|load|click|mouseover|focus|blur|submit)\s*=/i',
                action: WafAction::Block,
                severity: WafSeverity::Error,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '941130',
                message: 'XSS: data: URI with text/html',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/data\s*:\s*text\/html/i',
                action: WafAction::Block,
                severity: WafSeverity::Error,
                paranoiaLevel: 2,
            ),
        ];
    }

    /**
     * @return list<WafRule>
     */
    private static function pathTraversalRules(): array
    {
        return [
            new WafRule(
                id: '930100',
                message: 'Path Traversal: ../ detected',
                targets: [WafTarget::Uri, WafTarget::Args],
                operator: WafOperator::Regex,
                pattern: '/(\.\.|%2e%2e)(\/|%2f|\\\\|%5c)/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '930110',
                message: 'Path Traversal: /etc/passwd access attempt',
                targets: [WafTarget::Uri, WafTarget::Args],
                operator: WafOperator::Regex,
                pattern: '/\/etc\/(passwd|shadow|hosts)/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '930120',
                message: 'Path Traversal: Windows path access attempt',
                targets: [WafTarget::Uri, WafTarget::Args],
                operator: WafOperator::Regex,
                pattern: '/(c:|d:)(\\\\|%5c)/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
        ];
    }

    /**
     * @return list<WafRule>
     */
    private static function commandInjectionRules(): array
    {
        return [
            new WafRule(
                id: '932100',
                message: 'Remote Command Execution: shell metacharacters',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/[;|`].*\b(cat|ls|whoami|id|uname|curl|wget|nc|bash|sh|cmd|powershell)\b/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '932110',
                message: 'Remote Command Execution: command chaining',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/&&\s*\b(cat|ls|whoami|id|rm|wget|curl)\b/i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '932120',
                message: 'Remote Command Execution: backtick command substitution',
                targets: [WafTarget::Args, WafTarget::Body],
                operator: WafOperator::Regex,
                pattern: '/`[^`]+`/',
                action: WafAction::Block,
                severity: WafSeverity::Error,
                paranoiaLevel: 2,
            ),
        ];
    }

    /**
     * @return list<WafRule>
     */
    private static function rfiLfiRules(): array
    {
        return [
            new WafRule(
                id: '931100',
                message: 'Remote File Inclusion: URL in parameter',
                targets: [WafTarget::Args],
                operator: WafOperator::Regex,
                pattern: '/^https?:\/\//i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
            new WafRule(
                id: '931110',
                message: 'Local File Inclusion: PHP wrapper detected',
                targets: [WafTarget::Args, WafTarget::Uri],
                operator: WafOperator::Regex,
                pattern: '/\b(php|data|expect|input|filter):\/\//i',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
        ];
    }

    /**
     * @return list<WafRule>
     */
    private static function protocolViolationRules(): array
    {
        return [
            new WafRule(
                id: '920100',
                message: 'HTTP Protocol Violation: invalid request method',
                targets: [WafTarget::Method],
                operator: WafOperator::Regex,
                pattern: '/^(?!GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS|TRACE)/',
                action: WafAction::Block,
                severity: WafSeverity::Warning,
                paranoiaLevel: 2,
            ),
            new WafRule(
                id: '920110',
                message: 'HTTP Request Smuggling: null byte in URI',
                targets: [WafTarget::Uri],
                operator: WafOperator::Regex,
                pattern: '/%00|\\x00/',
                action: WafAction::Block,
                severity: WafSeverity::Critical,
                paranoiaLevel: 1,
            ),
        ];
    }
}
