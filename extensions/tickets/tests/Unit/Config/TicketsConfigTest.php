<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Domain\TicketPriority;

final class TicketsConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreApplied(): void
    {
        $config = TicketsConfig::fromArray([]);

        self::assertFalse($config->autoAssignEnabled);
        self::assertSame('round_robin', $config->autoAssignStrategy);
        self::assertTrue($config->emailNotificationsEnabled);
        self::assertFalse($config->contactFormIntegration);
        self::assertSame([], $config->slaRules);
        self::assertSame(7, $config->autoCloseAfterDays);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = TicketsConfig::fromArray([
            'auto_assign_enabled' => true,
            'auto_assign_strategy' => 'load_balanced',
            'email_notifications_enabled' => false,
            'contact_form_integration' => true,
            'auto_close_after_days' => 14,
            'sla_rules' => [
                [
                    'priority' => 'critical',
                    'first_response_minutes' => 15,
                    'resolution_minutes' => 60,
                    'escalation_rules' => [
                        ['threshold_minutes' => 30, 'action' => 'notify_manager'],
                    ],
                ],
            ],
        ]);

        self::assertTrue($config->autoAssignEnabled);
        self::assertSame('load_balanced', $config->autoAssignStrategy);
        self::assertFalse($config->emailNotificationsEnabled);
        self::assertTrue($config->contactFormIntegration);
        self::assertSame(14, $config->autoCloseAfterDays);
        self::assertCount(1, $config->slaRules);
        self::assertSame(TicketPriority::Critical, $config->slaRules[0]->priority);
    }

    #[Test]
    public function defaultConstructorUsesDefaults(): void
    {
        $config = new TicketsConfig();

        self::assertFalse($config->autoAssignEnabled);
        self::assertSame('round_robin', $config->autoAssignStrategy);
        self::assertTrue($config->emailNotificationsEnabled);
    }

    #[Test]
    public function fromArrayWithPartialData(): void
    {
        $config = TicketsConfig::fromArray([
            'auto_assign_enabled' => true,
        ]);

        self::assertTrue($config->autoAssignEnabled);
        self::assertSame('round_robin', $config->autoAssignStrategy);
        self::assertTrue($config->emailNotificationsEnabled);
    }
}
