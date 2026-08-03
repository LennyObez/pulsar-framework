<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Form\Csrf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Form\Config\CsrfFormConfig;
use Pulsar\Extension\Form\Csrf\FormCsrfManager;
use Pulsar\Extension\Form\Exception\CsrfException;
use Pulsar\Tests\Unit\Extension\Form\Stub\InMemorySession;

#[CoversClass(FormCsrfManager::class)]
#[CoversClass(CsrfException::class)]
final class FormCsrfManagerTest extends TestCase
{
    private InMemorySession $session;
    private FormCsrfManager $manager;

    protected function setUp(): void
    {
        $this->session = new InMemorySession();
        $config = CsrfFormConfig::fromArray(['ttl' => 3600]);
        $this->manager = new FormCsrfManager($this->session, $config);
    }

    #[Test]
    public function it_generates_and_validates_tokens(): void
    {
        $token = $this->manager->generate('login', '/login');

        self::assertNotEmpty($token);

        // Should not throw
        $this->manager->validate($token, 'login', '/login');

        // Token is consumed after validation — second attempt should fail
        $this->expectException(CsrfException::class);
        $this->manager->validate($token, 'login', '/login');
    }

    #[Test]
    public function it_rejects_empty_token(): void
    {
        $this->manager->generate('form1', '/submit');

        $this->expectException(CsrfException::class);
        $this->expectExceptionMessageIsOrContains('missing');
        $this->manager->validate('', 'form1', '/submit');
    }

    #[Test]
    public function it_rejects_invalid_token(): void
    {
        $this->manager->generate('form1', '/submit');

        $this->expectException(CsrfException::class);
        $this->expectExceptionMessageIsOrContains('invalid');
        $this->manager->validate('wrong-token', 'form1', '/submit');
    }

    #[Test]
    public function it_rejects_token_for_wrong_form(): void
    {
        $token = $this->manager->generate('form1', '/submit');

        $this->expectException(CsrfException::class);
        $this->manager->validate($token, 'form2', '/submit');
    }

    #[Test]
    public function it_rejects_token_for_wrong_action(): void
    {
        $token = $this->manager->generate('form1', '/submit');

        $this->expectException(CsrfException::class);
        $this->manager->validate($token, 'form1', '/other');
    }

    #[Test]
    public function it_rejects_expired_tokens(): void
    {
        $config = CsrfFormConfig::fromArray(['ttl' => 0]); // Immediate expiry
        $manager = new FormCsrfManager($this->session, $config);

        $token = $manager->generate('form1', '/submit');
        sleep(1);

        $this->expectException(CsrfException::class);
        $this->expectExceptionMessageIsOrContains('expired');
        $manager->validate($token, 'form1', '/submit');
    }

    #[Test]
    public function tokens_are_unique_per_form(): void
    {
        $token1 = $this->manager->generate('form1', '/action1');
        $token2 = $this->manager->generate('form2', '/action2');

        self::assertNotSame($token1, $token2);
    }
}
