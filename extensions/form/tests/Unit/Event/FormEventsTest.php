<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Contract\FormInterface;
use Pulsar\Extension\Form\Event\ConsentCapturedEvent;
use Pulsar\Extension\Form\Event\PostSubmitEvent;
use Pulsar\Extension\Form\Event\PreSubmitEvent;
use Pulsar\Extension\Form\Event\PreValidateEvent;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;

final class FormEventsTest extends TestCase
{
    #[Test]
    public function preSubmitEventExposesFormAndData(): void
    {
        $form = $this->createStub(FormInterface::class);
        $data = ['name' => 'John'];
        $event = new PreSubmitEvent($form, $data);

        self::assertSame($form, $event->form);
        self::assertSame($data, $event->data);
    }

    #[Test]
    public function preSubmitEventDataIsMutable(): void
    {
        $form = $this->createStub(FormInterface::class);
        $event = new PreSubmitEvent($form, ['name' => 'John']);

        $event->data['name'] = 'Jane';
        self::assertSame('Jane', $event->data['name']);
    }

    #[Test]
    public function postSubmitEventExposesForm(): void
    {
        $form = $this->createStub(FormInterface::class);
        $event = new PostSubmitEvent($form);

        self::assertSame($form, $event->form);
    }

    #[Test]
    public function preValidateEventExposesForm(): void
    {
        $form = $this->createStub(FormInterface::class);
        $event = new PreValidateEvent($form);

        self::assertSame($form, $event->form);
    }

    #[Test]
    public function consentCapturedEventExposesFieldAndEvidence(): void
    {
        $form = $this->createStub(FormInterface::class);
        $evidence = new ConsentEvidence(
            timestamp: '2024-01-01T00:00:00+00:00',
            purpose: 'marketing',
            policyVersion: 'v1.0',
            locale: 'en_US',
            subject: 'user@example.com',
            correlationId: 'abc',
            templateHash: 'tpl',
            policyTextHash: 'pol',
        );

        $event = new ConsentCapturedEvent($form, 'consent_field', $evidence);

        self::assertSame($form, $event->form);
        self::assertSame('consent_field', $event->fieldName);
        self::assertSame($evidence, $event->evidence);
    }
}
