<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Resource\AllergyReaction;
use Pulsar\Extension\Fhir\Resource\BundleEntry;
use Pulsar\Extension\Fhir\Resource\BundleLink;
use Pulsar\Extension\Fhir\Resource\CodeableConcept;
use Pulsar\Extension\Fhir\Resource\ContactPoint;
use Pulsar\Extension\Fhir\Resource\FhirVersion;
use Pulsar\Extension\Fhir\Resource\HumanName;
use Pulsar\Extension\Fhir\Resource\Identifier;
use Pulsar\Extension\Fhir\Resource\OperationOutcomeIssue;
use Pulsar\Extension\Fhir\Resource\Period;
use Pulsar\Extension\Fhir\Resource\Quantity;
use Pulsar\Extension\Fhir\Resource\Reference;
use Pulsar\Extension\Fhir\Resource\ResourceType;

use function count;

#[CoversClass(Period::class)]
#[CoversClass(Quantity::class)]
#[CoversClass(Reference::class)]
#[CoversClass(HumanName::class)]
#[CoversClass(ContactPoint::class)]
#[CoversClass(BundleEntry::class)]
#[CoversClass(BundleLink::class)]
#[CoversClass(AllergyReaction::class)]
#[CoversClass(OperationOutcomeIssue::class)]
final class FhirDataTypesTest extends TestCase
{
    // --- Period ---

    #[Test]
    public function periodToArrayWithBothDates(): void
    {
        $period = new Period('2026-01-01', '2026-12-31');
        $array = $period->toArray();

        self::assertSame('2026-01-01', $array['start']);
        self::assertSame('2026-12-31', $array['end']);
    }

    #[Test]
    public function periodToArrayOmitsNulls(): void
    {
        $period = new Period();
        self::assertSame([], $period->toArray());
    }

    #[Test]
    public function periodFromArrayParsesCorrectly(): void
    {
        $period = Period::fromArray(['start' => '2026-01-01', 'end' => '2026-06-30']);

        self::assertSame('2026-01-01', $period->start);
        self::assertSame('2026-06-30', $period->end);
    }

    #[Test]
    public function periodFromArrayHandlesNonStringValues(): void
    {
        $period = Period::fromArray(['start' => 123, 'end' => null]);

        self::assertNull($period->start);
        self::assertNull($period->end);
    }

    // --- Quantity ---

    #[Test]
    public function quantityToArrayWithAllFields(): void
    {
        $qty = new Quantity(120.5, '<', 'mmHg', 'http://unitsofmeasure.org', 'mm[Hg]');
        $array = $qty->toArray();

        self::assertSame(120.5, $array['value']);
        self::assertSame('<', $array['comparator']);
        self::assertSame('mmHg', $array['unit']);
        self::assertSame('http://unitsofmeasure.org', $array['system']);
        self::assertSame('mm[Hg]', $array['code']);
    }

    #[Test]
    public function quantityToArrayOmitsNulls(): void
    {
        $qty = new Quantity();
        self::assertSame([], $qty->toArray());
    }

    #[Test]
    public function quantityFromArrayParsesNumericValue(): void
    {
        $qty = Quantity::fromArray(['value' => '98.6', 'unit' => 'F']);

        self::assertSame(98.6, $qty->value);
        self::assertSame('F', $qty->unit);
    }

    #[Test]
    public function quantityFromArrayHandlesNonNumericValue(): void
    {
        $qty = Quantity::fromArray(['value' => 'not-a-number']);

        self::assertNull($qty->value);
    }

    // --- Reference ---

    #[Test]
    public function referenceToArrayWithAllFields(): void
    {
        $identifier = new Identifier(system: 'http://example.org', value: 'id-1');
        $ref = new Reference('Patient/p1', 'Patient', 'Jane Doe', $identifier);
        $array = $ref->toArray();

        self::assertSame('Patient/p1', $array['reference']);
        self::assertSame('Patient', $array['type']);
        self::assertSame('Jane Doe', $array['display']);
        self::assertArrayHasKey('identifier', $array);
    }

    #[Test]
    public function referenceToArrayOmitsNulls(): void
    {
        $ref = new Reference();
        self::assertSame([], $ref->toArray());
    }

    #[Test]
    public function referenceFromArrayWithIdentifier(): void
    {
        $ref = Reference::fromArray([
            'reference' => 'Patient/p1',
            'type' => 'Patient',
            'display' => 'Jane',
            'identifier' => ['system' => 'urn:test', 'value' => 'id-1'],
        ]);

        self::assertSame('Patient/p1', $ref->reference);
        self::assertNotNull($ref->identifier);
        self::assertSame('id-1', $ref->identifier->value);
    }

    #[Test]
    public function referenceFromArrayWithoutIdentifier(): void
    {
        $ref = Reference::fromArray(['reference' => 'Patient/p1']);

        self::assertNull($ref->identifier);
    }

    // --- HumanName ---

    #[Test]
    public function humanNameToArrayWithAllFields(): void
    {
        $name = new HumanName('official', 'Dr Jane Smith Jr', 'Smith', ['Jane'], ['Dr'], ['Jr']);
        $array = $name->toArray();

        self::assertSame('official', $array['use']);
        self::assertSame('Dr Jane Smith Jr', $array['text']);
        self::assertSame('Smith', $array['family']);
        self::assertSame(['Jane'], $array['given']);
        self::assertSame(['Dr'], $array['prefix']);
        self::assertSame(['Jr'], $array['suffix']);
    }

    #[Test]
    public function humanNameToArrayOmitsEmptyFields(): void
    {
        $name = new HumanName();
        self::assertSame([], $name->toArray());
    }

    #[Test]
    public function humanNameFromArrayParsesCorrectly(): void
    {
        $name = HumanName::fromArray([
            'use' => 'usual',
            'family' => 'Doe',
            'given' => ['John', 'Q'],
            'prefix' => ['Mr'],
            'suffix' => [],
        ]);

        self::assertSame('usual', $name->use);
        self::assertSame('Doe', $name->family);
        self::assertSame(['John', 'Q'], $name->given);
        self::assertSame(['Mr'], $name->prefix);
        self::assertSame([], $name->suffix);
    }

    // --- ContactPoint ---

    #[Test]
    public function contactPointToArrayWithAllFields(): void
    {
        $cp = new ContactPoint('phone', '+1-555-0100', 'home', 1);
        $array = $cp->toArray();

        self::assertSame('phone', $array['system']);
        self::assertSame('+1-555-0100', $array['value']);
        self::assertSame('home', $array['use']);
        self::assertSame(1, $array['rank']);
    }

    #[Test]
    public function contactPointToArrayOmitsNulls(): void
    {
        $cp = new ContactPoint();
        self::assertSame([], $cp->toArray());
    }

    #[Test]
    public function contactPointFromArrayParsesRank(): void
    {
        $cp = ContactPoint::fromArray(['system' => 'email', 'value' => 'a@b.com', 'rank' => '2']);

        self::assertSame('email', $cp->system);
        self::assertSame(2, $cp->rank);
    }

    #[Test]
    public function contactPointFromArrayHandlesNonNumericRank(): void
    {
        $cp = ContactPoint::fromArray(['rank' => 'abc']);

        self::assertNull($cp->rank);
    }

    // --- BundleEntry ---

    #[Test]
    public function bundleEntryToArrayWithAllFields(): void
    {
        $entry = new BundleEntry(
            fullUrl: 'Patient/p1',
            resource: ['resourceType' => 'Patient'],
            request: ['method' => 'POST', 'url' => 'Patient'],
            response: ['status' => '201 Created'],
        );

        $array = $entry->toArray();

        self::assertSame('Patient/p1', $array['fullUrl']);
        self::assertSame('Patient', $array['resource']['resourceType']);
        self::assertSame('POST', $array['request']['method']);
        self::assertSame('201 Created', $array['response']['status']);
    }

    #[Test]
    public function bundleEntryToArrayOmitsNulls(): void
    {
        $entry = new BundleEntry();
        self::assertSame([], $entry->toArray());
    }

    #[Test]
    public function bundleEntryFromArrayParsesCorrectly(): void
    {
        $entry = BundleEntry::fromArray([
            'fullUrl' => 'http://example.com/Patient/1',
            'resource' => ['id' => '1'],
            'request' => ['method' => 'GET', 'url' => 'Patient/1'],
        ]);

        self::assertSame('http://example.com/Patient/1', $entry->fullUrl);
        self::assertSame(['id' => '1'], $entry->resource);
        self::assertNotNull($entry->request);
        self::assertNull($entry->response);
    }

    // --- BundleLink ---

    #[Test]
    public function bundleLinkToArrayReturnsRelationAndUrl(): void
    {
        $link = new BundleLink('self', 'http://example.com/fhir/Patient');

        self::assertSame(['relation' => 'self', 'url' => 'http://example.com/fhir/Patient'], $link->toArray());
    }

    #[Test]
    public function bundleLinkFromArrayParsesCorrectly(): void
    {
        $link = BundleLink::fromArray(['relation' => 'next', 'url' => 'http://example.com?page=2']);

        self::assertSame('next', $link->relation);
        self::assertSame('http://example.com?page=2', $link->url);
    }

    // --- AllergyReaction ---

    #[Test]
    public function allergyReactionToArrayWithAllFields(): void
    {
        $substance = new CodeableConcept(text: 'Peanuts');
        $manifestation = [new CodeableConcept(text: 'Hives')];
        $reaction = new AllergyReaction($substance, $manifestation, 'severe', '2026-01-15', 'Full body reaction');
        $array = $reaction->toArray();

        self::assertSame('Peanuts', $array['substance']['text']);
        self::assertCount(1, $array['manifestation']);
        self::assertSame('severe', $array['severity']);
        self::assertSame('2026-01-15', $array['onset']);
        self::assertSame('Full body reaction', $array['description']);
    }

    #[Test]
    public function allergyReactionToArrayOmitsNulls(): void
    {
        $reaction = new AllergyReaction();
        self::assertSame([], $reaction->toArray());
    }

    #[Test]
    public function allergyReactionFromArrayParsesSubstanceAndManifestations(): void
    {
        $reaction = AllergyReaction::fromArray([
            'substance' => ['text' => 'Latex'],
            'manifestation' => [['text' => 'Rash'], ['text' => 'Swelling']],
            'severity' => 'moderate',
        ]);

        self::assertNotNull($reaction->substance);
        self::assertSame('Latex', $reaction->substance->text);
        self::assertCount(2, $reaction->manifestation);
        self::assertSame('moderate', $reaction->severity);
    }

    // --- OperationOutcomeIssue ---

    #[Test]
    public function operationOutcomeIssueToArrayWithAllFields(): void
    {
        $details = new CodeableConcept(text: 'Not found');
        $issue = new OperationOutcomeIssue(
            'error',
            'not-found',
            $details,
            'Patient/p1 was not found',
            ['Patient'],
            ['Patient.id'],
        );
        $array = $issue->toArray();

        self::assertSame('error', $array['severity']);
        self::assertSame('not-found', $array['code']);
        self::assertSame('Not found', $array['details']['text']);
        self::assertSame('Patient/p1 was not found', $array['diagnostics']);
        self::assertSame(['Patient'], $array['location']);
        self::assertSame(['Patient.id'], $array['expression']);
    }

    #[Test]
    public function operationOutcomeIssueToArrayOmitsNulls(): void
    {
        $issue = new OperationOutcomeIssue('warning', 'informational');
        $array = $issue->toArray();

        self::assertSame('warning', $array['severity']);
        self::assertSame('informational', $array['code']);
        self::assertArrayNotHasKey('details', $array);
        self::assertArrayNotHasKey('diagnostics', $array);
        self::assertArrayNotHasKey('location', $array);
        self::assertArrayNotHasKey('expression', $array);
    }

    #[Test]
    public function operationOutcomeIssueFromArrayUsesDefaults(): void
    {
        $issue = OperationOutcomeIssue::fromArray([]);

        self::assertSame('error', $issue->severity);
        self::assertSame('processing', $issue->code);
    }

    #[Test]
    public function operationOutcomeIssueFromArrayParsesAllFields(): void
    {
        $issue = OperationOutcomeIssue::fromArray([
            'severity' => 'fatal',
            'code' => 'exception',
            'details' => ['text' => 'Internal error'],
            'diagnostics' => 'Stack trace here',
            'location' => ['/f:Patient/f:name'],
            'expression' => ['Patient.name'],
        ]);

        self::assertSame('fatal', $issue->severity);
        self::assertNotNull($issue->details);
        self::assertSame('Stack trace here', $issue->diagnostics);
        self::assertSame(['/f:Patient/f:name'], $issue->location);
    }

    // --- Enums ---

    #[Test]
    public function fhirVersionR4HasCorrectValue(): void
    {
        self::assertSame('4.0.1', FhirVersion::R4->value);
    }

    #[Test]
    public function fhirVersionR5HasCorrectValue(): void
    {
        self::assertSame('5.0.0', FhirVersion::R5->value);
    }

    #[Test]
    public function resourceTypePatientHasCorrectValue(): void
    {
        self::assertSame('Patient', ResourceType::Patient->value);
    }

    #[Test]
    public function resourceTypeCoverageForAllCases(): void
    {
        $cases = ResourceType::cases();

        self::assertGreaterThanOrEqual(12, count($cases));
        self::assertContains(ResourceType::OperationOutcome, $cases);
        self::assertContains(ResourceType::AuditEvent, $cases);
        self::assertContains(ResourceType::Bundle, $cases);
    }
}
