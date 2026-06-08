<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Fhir\Terminology\CodeSystemInterface;
use Pulsar\Extension\Fhir\Terminology\ConceptDefinition;
use Pulsar\Extension\Fhir\Terminology\ConceptMap;
use Pulsar\Extension\Fhir\Terminology\ConceptMapEntry;
use Pulsar\Extension\Fhir\Terminology\TerminologyServiceInterface;
use Pulsar\Extension\Fhir\Terminology\ValidationResult;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

/**
 * Aggregates code systems, value sets, and concept maps into a unified
 * terminology service.
 */
#[Internal(reason: 'Terminology service wiring; use TerminologyServiceInterface')]
final class TerminologyService implements TerminologyServiceInterface
{
    /** @var array<string, CodeSystemInterface> URL => code system */
    private array $codeSystems = [];

    /** @var array<string, ConceptMap> URL => concept map */
    private array $conceptMaps = [];

    public function __construct(
        private readonly ValueSetValidator $valueSetValidator,
    ) {}

    /**
     * Register a code system.
     */
    public function registerCodeSystem(CodeSystemInterface $codeSystem): void
    {
        $this->codeSystems[$codeSystem->url()] = $codeSystem;
    }

    /**
     * Register a concept map.
     */
    public function registerConceptMap(ConceptMap $conceptMap): void
    {
        $this->conceptMaps[$conceptMap->url] = $conceptMap;
    }

    #[Override]
    public function lookup(string $system, string $code): ?ConceptDefinition
    {
        $codeSystem = $this->codeSystems[$system] ?? null;

        if ($codeSystem === null) {
            return null;
        }

        return $codeSystem->lookup($code);
    }

    #[Override]
    public function validate(string $valueSetUrl, string $system, string $code): ValidationResult
    {
        return $this->valueSetValidator->validate($valueSetUrl, $system, $code);
    }

    /**
     * @return list<ConceptMapEntry>
     */
    #[Override]
    public function translate(string $conceptMapUrl, string $sourceCode): array
    {
        $conceptMap = $this->conceptMaps[$conceptMapUrl] ?? null;

        if ($conceptMap === null) {
            return [];
        }

        return $conceptMap->translate($sourceCode);
    }
}
