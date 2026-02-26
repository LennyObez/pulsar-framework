<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Builder\FormBuilder;
use Pulsar\Extension\Form\Config\FormConfig;
use Pulsar\Extension\Form\Event\ConsentCapturedEvent;
use Pulsar\Extension\Form\Event\FormEvent;
use Pulsar\Extension\Form\Event\PostSubmitEvent;
use Pulsar\Extension\Form\Event\PreSubmitEvent;
use Pulsar\Extension\Form\Event\PreValidateEvent;
use Pulsar\Extension\Form\Field\Regulated\ConsentCheckbox;
use Pulsar\Extension\Form\Field\Regulated\ConsentEvidence;
use Pulsar\Extension\Form\Field\TextField;
use Pulsar\Http\Validation\Validator;

#[CoversClass(FormEvent::class)]
#[CoversClass(PreSubmitEvent::class)]
#[CoversClass(PostSubmitEvent::class)]
#[CoversClass(PreValidateEvent::class)]
#[CoversClass(ConsentCapturedEvent::class)]
final class FormEventTest extends TestCase
{
    #[Test]
    public function pre_submit_event_holds_form_and_data(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $form = new FormBuilder($config, new Validator())
            ->id('test')
            ->add(new TextField('x', 'X'))
            ->build();

        $event = new PreSubmitEvent($form, ['x' => 'value']);

        self::assertSame($form, $event->form);
        self::assertSame(['x' => 'value'], $event->data);
    }

    #[Test]
    public function post_submit_event_holds_form(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $form = new FormBuilder($config, new Validator())
            ->id('test')
            ->build();

        $event = new PostSubmitEvent($form);

        self::assertSame($form, $event->form);
    }

    #[Test]
    public function pre_validate_event_holds_form(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $form = new FormBuilder($config, new Validator())
            ->id('test')
            ->build();

        $event = new PreValidateEvent($form);

        self::assertSame($form, $event->form);
    }

    #[Test]
    public function consent_captured_event_holds_evidence(): void
    {
        $config = FormConfig::fromArray(['csrf' => ['enabled' => false]]);
        $form = new FormBuilder($config, new Validator())
            ->id('test')
            ->add(new ConsentCheckbox('gdpr', 'GDPR', 'consent', 'v1', 'text'))
            ->build();

        $evidence = new ConsentEvidence(
            timestamp: '2026-02-17T10:00:00+00:00',
            purpose: 'consent',
            policyVersion: 'v1',
            locale: 'en',
            subject: 'user-1',
            correlationId: 'req-1',
            templateHash: 'hash1',
            policyTextHash: 'hash2',
        );

        $event = new ConsentCapturedEvent($form, 'gdpr', $evidence);

        self::assertSame($form, $event->form);
        self::assertSame('gdpr', $event->fieldName);
        self::assertSame($evidence, $event->evidence);
    }
}
