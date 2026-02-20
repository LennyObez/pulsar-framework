<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\AI;

use Pulsar\Api\Api;

/**
 * Registry of named prompt templates for the AI content assistant.
 *
 * Templates are registered during extension boot and can be overridden
 * by plugins for customization or localization.
 */
#[Api(since: '1.0.0')]
final class PromptTemplateRegistry
{
    /** @var array<string, PromptTemplate> */
    private array $templates = [];

    public function register(PromptTemplate $template): void
    {
        $this->templates[$template->name] = $template;
    }

    public function get(string $name): ?PromptTemplate
    {
        return $this->templates[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    /**
     * @return array<string, PromptTemplate>
     */
    public function all(): array
    {
        return $this->templates;
    }
}
