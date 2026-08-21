<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

use function file_get_contents;
use function ini_get;
use function ini_set;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * `EntitySerializationGuard` enforced "a domain entity must never bypass the
 * resource transformation layer" by inspecting a controller's return value —
 * and `check()` had no caller anywhere in the tree. `ApiWiring` built the
 * object and put it in the container; nothing ever asked it a question.
 *
 * The guard was deleted rather than wired, because the property it describes is
 * already structural. `Kernel::invokeHandler()` accepts a `ResponseInterface`
 * or a string and refuses everything else, unconditionally and in every
 * environment — so there is no auto-serialization path for an entity to travel
 * down. Wiring the guard would have added a second check behind a check that
 * cannot be got past, and configured it off by default through
 * `api.entity_serialization_ban`.
 *
 * This test is what the deletion rests on. If a future change ever introduces
 * an auto-serializing return path — a controller returning an array that the
 * kernel JSON-encodes, say — it fails, and the argument above stops being true
 * before anyone relies on it.
 */
#[CoversClass(Kernel::class)]
final class EntityNeverAutoSerializesTest extends TestCase
{
    /** Anything the kernel printed instead of returning, during the last dispatch. */
    private string $printed = '';

    #[Test]
    public function aControllerReturningADomainEntityNeverPutsItsFieldsOnTheWire(): void
    {
        $response = $this->dispatch(static fn(): object => new PatientRecord());

        self::assertGreaterThanOrEqual(500, $response->getStatusCode());

        // Both the response and the diagnostic the kernel emitted: a field that
        // reaches neither has reached nobody.
        $emitted = (string) $response->getBody() . $this->printed;
        self::assertStringNotContainsString('NL-8842-113', $emitted);
        self::assertStringNotContainsString('confidential', $emitted);
    }

    /**
     * An array is the shape a developer most easily mistakes for "the framework
     * will encode this for me". It is refused too: JSON has to be asked for.
     */
    #[Test]
    public function aControllerReturningAnArrayIsRefusedRatherThanEncoded(): void
    {
        $response = $this->dispatch(static fn(): array => ['ssn' => '123-45-6789']);

        self::assertGreaterThanOrEqual(500, $response->getStatusCode());
        self::assertStringNotContainsString('123-45-6789', (string) $response->getBody() . $this->printed);
    }

    /**
     * The two shapes that are accepted, so the test above is read as a boundary
     * rather than as "the kernel refuses things".
     */
    #[Test]
    public function aResponseAndAStringBothPassThrough(): void
    {
        $fromResponse = $this->dispatch(static fn(): ResponseInterface => Response::html('rendered'));
        self::assertSame(200, $fromResponse->getStatusCode());
        self::assertStringContainsString('rendered', (string) $fromResponse->getBody());

        $fromString = $this->dispatch(static fn(): string => 'rendered');
        self::assertSame(200, $fromString->getStatusCode());
        self::assertStringContainsString('rendered', (string) $fromString->getBody());
    }

    private function dispatch(callable $handler): ResponseInterface
    {
        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/records',
            handler: static fn(ServerRequestInterface $request, array $params): mixed => $handler(),
        ));

        // A kernel with no exception handler wired reports the failure through
        // error_log(). Point that at a file for the duration: it keeps the line
        // off the runner's output, and it is the third place a field could leak
        // to, so the assertions read it back.
        $logFile = tempnam(sys_get_temp_dir(), 'pulsar_kernel_err_');
        self::assertIsString($logFile);
        $previousLog = (string) ini_get('error_log');

        ini_set('error_log', $logFile);

        try {
            return $kernel->handle(new ServerRequest(method: 'GET', uri: '/records'));
        } finally {
            ini_set('error_log', $previousLog);
            $this->printed = (string) file_get_contents($logFile);
            @unlink($logFile);
        }
    }
}

/**
 * Stands in for an application's domain entity: plain public state, no
 * transformation layer, and fields no response should ever carry by accident.
 */
final class PatientRecord
{
    public string $nationalId = 'NL-8842-113';
    public string $diagnosis = 'confidential';
}
