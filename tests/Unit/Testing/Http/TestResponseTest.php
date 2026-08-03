<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Http;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\Response;
use Pulsar\Testing\Http\TestResponse;

#[CoversClass(TestResponse::class)]
final class TestResponseTest extends TestCase
{
    #[Test]
    public function assert_ok_passes_for_200(): void
    {
        $response = new TestResponse(new Response(200));

        $response->assertOk();
    }

    #[Test]
    public function assert_ok_fails_for_non_200(): void
    {
        $response = new TestResponse(new Response(404));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Expected status code 200, but received 404');

        $response->assertOk();
    }

    #[Test]
    public function assert_created_passes_for_201(): void
    {
        $response = new TestResponse(new Response(201));

        $response->assertCreated();
    }

    #[Test]
    public function assert_no_content_passes_for_204(): void
    {
        $response = new TestResponse(new Response(204));

        $response->assertNoContent();
    }

    #[Test]
    public function assert_not_found_passes_for_404(): void
    {
        $response = new TestResponse(new Response(404));

        $response->assertNotFound();
    }

    #[Test]
    public function assert_forbidden_passes_for_403(): void
    {
        $response = new TestResponse(new Response(403));

        $response->assertForbidden();
    }

    #[Test]
    public function assert_unauthorized_passes_for_401(): void
    {
        $response = new TestResponse(new Response(401));

        $response->assertUnauthorized();
    }

    #[Test]
    public function assert_unprocessable_passes_for_422(): void
    {
        $response = new TestResponse(new Response(422));

        $response->assertUnprocessable();
    }

    #[Test]
    public function assert_status_for_arbitrary_code(): void
    {
        $response = new TestResponse(new Response(418));

        $response->assertStatus(418);
    }

    #[Test]
    public function assert_successful_passes_for_2xx(): void
    {
        $response = new TestResponse(new Response(200));
        $response->assertSuccessful();

        $response = new TestResponse(new Response(201));
        $response->assertSuccessful();

        $response = new TestResponse(new Response(204));
        $response->assertSuccessful();
    }

    #[Test]
    public function assert_successful_fails_for_non_2xx(): void
    {
        $response = new TestResponse(new Response(400));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Expected a successful status code (2xx)');

        $response->assertSuccessful();
    }

    #[Test]
    public function assert_header_passes_when_present(): void
    {
        $response = new TestResponse(
            new Response(200, '', ['X-Custom' => 'value']),
        );

        $response->assertHeader('X-Custom');
    }

    #[Test]
    public function assert_header_with_value(): void
    {
        $response = new TestResponse(
            new Response(200, '', ['X-Custom' => 'expected']),
        );

        $response->assertHeader('X-Custom', 'expected');
    }

    #[Test]
    public function assert_header_missing_passes_when_absent(): void
    {
        $response = new TestResponse(new Response(200));

        $response->assertHeaderMissing('X-Custom');
    }

    #[Test]
    public function assert_redirect_passes_for_302(): void
    {
        $response = new TestResponse(
            new Response(302, '', ['Location' => '/dashboard']),
        );

        $response->assertRedirect();
    }

    #[Test]
    public function assert_redirect_with_url(): void
    {
        $response = new TestResponse(
            new Response(302, '', ['Location' => '/dashboard']),
        );

        $response->assertRedirect('/dashboard');
    }

    #[Test]
    public function assert_json_passes_for_json_response(): void
    {
        $response = new TestResponse(
            Response::json(['status' => 'ok']),
        );

        $response->assertJson();
    }

    #[Test]
    public function assert_json_fragment(): void
    {
        $response = new TestResponse(
            Response::json(['name' => 'John', 'age' => 30]),
        );

        $response->assertJsonFragment(['name' => 'John']);
    }

    #[Test]
    public function assert_json_path(): void
    {
        $response = new TestResponse(
            Response::json(['data' => ['user' => ['name' => 'Alice']]]),
        );

        $response->assertJsonPath('data.user.name', 'Alice');
    }

    #[Test]
    public function assert_json_structure(): void
    {
        $response = new TestResponse(
            Response::json(['id' => 1, 'name' => 'Test', 'email' => 'a@b.com']),
        );

        $response->assertJsonStructure(['id', 'name', 'email']);
    }

    #[Test]
    public function assert_json_count(): void
    {
        $response = new TestResponse(
            Response::json(['items' => [1, 2, 3]]),
        );

        $response->assertJsonCount(3, 'items');
    }

    #[Test]
    public function assert_see_passes_when_text_found(): void
    {
        $response = new TestResponse(
            Response::html('<h1>Welcome</h1>'),
        );

        $response->assertSee('Welcome');
    }

    #[Test]
    public function assert_dont_see_passes_when_text_absent(): void
    {
        $response = new TestResponse(
            Response::html('<h1>Welcome</h1>'),
        );

        $response->assertDontSee('Goodbye');
    }

    #[Test]
    public function body_returns_response_content(): void
    {
        $response = new TestResponse(
            Response::text('Hello, World!'),
        );

        self::assertSame('Hello, World!', $response->body());
    }

    #[Test]
    public function status_returns_status_code(): void
    {
        $response = new TestResponse(new Response(201));

        self::assertSame(201, $response->status());
    }

    #[Test]
    public function json_decodes_body(): void
    {
        $response = new TestResponse(
            Response::json(['key' => 'value']),
        );

        self::assertSame(['key' => 'value'], $response->json());
    }

    #[Test]
    public function assertions_are_chainable(): void
    {
        $response = new TestResponse(
            Response::json(['status' => 'ok']),
        );

        $response
            ->assertOk()
            ->assertJson()
            ->assertJsonFragment(['status' => 'ok'])
            ->assertSee('ok');
    }
}
