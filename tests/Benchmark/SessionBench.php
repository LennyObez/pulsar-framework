<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Config\SessionConfig;
use Pulsar\Security\Session\Handler\ArrayHandler;
use Pulsar\Security\Session\SessionManager;
use ReflectionProperty;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class SessionBench
{
    private ArrayHandler $handler;

    private SessionConfig $config;

    private string $sessionId;

    public function setUp(): void
    {
        $this->handler = new ArrayHandler();
        $this->config = new SessionConfig(
            cookieName: 'BENCH_SESSION',
            lifetime: 3600,
            cookieHttpOnly: true,
            cookieSecure: false,
            cookieSameSite: 'Lax',
            regenerateOnPrivilegeChange: false,
            handler: 'array',
            encryption: false,
        );

        // Seed the session store with test data
        $manager = new SessionManager(
            handler: $this->handler,
            config: $this->config,
            validators: [],
            encryption: null,
        );

        $manager->start();
        $manager->set('user_id', 42);
        $manager->set('role', 'admin');
        $manager->set('preferences', ['theme' => 'dark', 'lang' => 'en']);
        $manager->save();

        $this->sessionId = $manager->id();
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchLoadVerifyMemory(): void
    {
        $manager = new SessionManager(
            handler: $this->handler,
            config: $this->config,
            validators: [],
            encryption: null,
        );

        // Inject the known session ID so it resumes from the ArrayHandler
        $reflection = new ReflectionProperty(SessionManager::class, 'sessionId');
        $reflection->setValue($manager, $this->sessionId);

        $manager->start();
        $_ = $manager->get('user_id');
        $_ = $manager->get('role');
        $manager->save();
    }
}
