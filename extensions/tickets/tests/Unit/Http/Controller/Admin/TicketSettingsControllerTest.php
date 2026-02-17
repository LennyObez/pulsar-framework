<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Http\Controller\Admin\TicketSettingsController;

#[CoversClass(TicketSettingsController::class)]
final class TicketSettingsControllerTest extends TestCase
{
    #[Test]
    public function showReturnsCurrentSettings(): void
    {
        $config = new TicketsConfig(
            autoAssignEnabled: true,
            autoAssignStrategy: 'load_balanced',
            emailNotificationsEnabled: false,
            contactFormIntegration: true,
            autoCloseAfterDays: 14,
        );

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = new TicketSettingsController($config, $gate);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $identity],
        ]);

        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        self::assertTrue($body['settings']['auto_assign_enabled']);
        self::assertSame('load_balanced', $body['settings']['auto_assign_strategy']);
        self::assertFalse($body['settings']['email_notifications_enabled']);
        self::assertTrue($body['settings']['contact_form_integration']);
        self::assertSame(14, $body['settings']['auto_close_after_days']);
    }

    #[Test]
    public function showIncludesSlaRulesFromConfig(): void
    {
        $config = TicketsConfig::fromArray([
            'sla_rules' => [
                [
                    'priority' => 'critical',
                    'first_response_minutes' => 15,
                    'resolution_minutes' => 60,
                ],
            ],
        ]);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = new TicketSettingsController($config, $gate);

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $identity],
        ]);

        $response = $controller->show($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['settings']['sla_rules']);
        self::assertSame('critical', $body['settings']['sla_rules'][0]['priority']);
        self::assertSame(15, $body['settings']['sla_rules'][0]['first_response_minutes']);
        self::assertSame(60, $body['settings']['sla_rules'][0]['resolution_minutes']);
    }
}
