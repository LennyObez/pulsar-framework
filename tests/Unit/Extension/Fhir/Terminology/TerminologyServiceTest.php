<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Terminology;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Internal\TerminologyService;
use Pulsar\Extension\Fhir\Terminology\CodeSystemInterface;
use Pulsar\Extension\Fhir\Terminology\ConceptDefinition;
use Pulsar\Extension\Fhir\Terminology\ConceptMap;
use Pulsar\Extension\Fhir\Terminology\ValueSetDefinition;
use Pulsar\Extension\Fhir\Terminology\ValueSetInclude;
use Pulsar\Extension\Fhir\Terminology\ValueSetValidator;

#[CoversClass(TerminologyService::class)]
final class TerminologyServiceTest extends TestCase
{
    public function testLookupInRegisteredCodeSystem(): void
    {
        $service = $this->createService();

        $codeSystem = $this->createCodeSystem(
            'http://loinc.org',
            'LOINC',
            ['2160-0' => new ConceptDefinition('2160-0', 'Creatinine')],
        );
        $service->registerCodeSystem($codeSystem);

        $result = $service->lookup('http://loinc.org', '2160-0');

        self::assertNotNull($result);
        self::assertSame('2160-0', $result->code);
        self::assertSame('Creatinine', $result->display);
    }

    public function testLookupInUnregisteredSystemReturnsNull(): void
    {
        $service = $this->createService();

        self::assertNull($service->lookup('http://unknown', 'code'));
    }

    public function testLookupNonexistentCodeReturnsNull(): void
    {
        $service = $this->createService();

        $codeSystem = $this->createCodeSystem('http://loinc.org', 'LOINC', []);
        $service->registerCodeSystem($codeSystem);

        self::assertNull($service->lookup('http://loinc.org', 'missing'));
    }

    public function testValidateAgainstValueSet(): void
    {
        $validator = new ValueSetValidator();
        $validator->register(new ValueSetDefinition(
            url: 'http://example.org/vs/gender',
            name: 'Gender',
            includes: [
                new ValueSetInclude(
                    system: 'http://hl7.org/fhir/administrative-gender',
                    concepts: [new ConceptDefinition('male', 'Male')],
                ),
            ],
        ));

        $service = new TerminologyService($validator);

        $result = $service->validate(
            'http://example.org/vs/gender',
            'http://hl7.org/fhir/administrative-gender',
            'male',
        );

        self::assertTrue($result->valid);
    }

    public function testTranslateWithRegisteredConceptMap(): void
    {
        $service = $this->createService();

        $map = new ConceptMap(
            url: 'http://example.org/cm/test',
            name: 'Test Map',
            sourceSystem: 'http://sys-a',
            targetSystem: 'http://sys-b',
        );
        $map->addMapping('A1', 'B1');
        $service->registerConceptMap($map);

        $results = $service->translate('http://example.org/cm/test', 'A1');

        self::assertCount(1, $results);
        self::assertSame('B1', $results[0]->targetCode);
    }

    public function testTranslateUnregisteredMapReturnsEmpty(): void
    {
        $service = $this->createService();

        self::assertSame([], $service->translate('http://unknown', 'A1'));
    }

    private function createService(): TerminologyService
    {
        return new TerminologyService(new ValueSetValidator());
    }

    /**
     * @param array<string, ConceptDefinition> $concepts
     */
    private function createCodeSystem(string $url, string $name, array $concepts): CodeSystemInterface
    {
        return new class ($url, $name, $concepts) implements CodeSystemInterface {
            /**
             * @param array<string, ConceptDefinition> $concepts
             */
            public function __construct(
                private readonly string $url,
                private readonly string $name,
                private readonly array $concepts,
            ) {}

            #[Override]
            public function url(): string
            {
                return $this->url;
            }

            #[Override]
            public function name(): string
            {
                return $this->name;
            }

            #[Override]
            public function lookup(string $code): ?ConceptDefinition
            {
                return $this->concepts[$code] ?? null;
            }

            #[Override]
            public function validate(string $code): bool
            {
                return isset($this->concepts[$code]);
            }

            #[Override]
            public function version(): string
            {
                return '1.0';
            }
        };
    }
}
