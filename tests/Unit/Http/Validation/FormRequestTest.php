<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\Validation\FormRequest;
use Pulsar\Http\Validation\ValidationException;
use stdClass;

final class FormRequestTest extends TestCase
{
    #[Test]
    public function validatedReturnsValidatedData(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [
                    'name' => 'required|string',
                    'email' => 'required|email',
                ];
            }
        };

        $request = $this->buildRequest(parsedBody: ['name' => 'Alice', 'email' => 'alice@example.com', 'extra' => 'ignored']);
        $form->setRequest($request);

        $data = $form->validated();

        self::assertSame('Alice', $data['name']);
        self::assertSame('alice@example.com', $data['email']);
        self::assertArrayNotHasKey('extra', $data);
    }

    #[Test]
    public function validatedThrowsOnInvalidData(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return ['name' => 'required'];
            }
        };

        $request = $this->buildRequest(parsedBody: ['name' => '']);
        $form->setRequest($request);

        $this->expectException(ValidationException::class);
        $form->validated();
    }

    #[Test]
    public function authorizeDefaultsToTrue(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [];
            }
        };

        self::assertTrue($form->authorize());
    }

    #[Test]
    public function authorizeCanBeOverridden(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [];
            }

            public function authorize(): bool
            {
                return false;
            }
        };

        self::assertFalse($form->authorize());
    }

    #[Test]
    public function inputReturnsFieldValue(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [];
            }
        };

        $request = $this->buildRequest(parsedBody: ['name' => 'Alice']);
        $form->setRequest($request);

        self::assertSame('Alice', $form->input('name'));
        self::assertNull($form->input('missing'));
        self::assertSame('default', $form->input('missing', 'default'));
    }

    #[Test]
    public function requestReturnsServerRequest(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [];
            }
        };

        $request = $this->buildRequest();
        $form->setRequest($request);

        self::assertSame($request, $form->request());
    }

    #[Test]
    public function userReturnsUserAttribute(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [];
            }
        };

        $user = new stdClass();
        $user->name = 'Alice';
        $request = $this->buildRequest()->withAttribute('_user', $user);
        $form->setRequest($request);

        self::assertSame($user, $form->user());
    }

    #[Test]
    public function userReturnsNullWhenNotAuthenticated(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [];
            }
        };

        $request = $this->buildRequest();
        $form->setRequest($request);

        self::assertNull($form->user());
    }

    #[Test]
    public function validatedCachesResult(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return ['name' => 'required'];
            }
        };

        $request = $this->buildRequest(parsedBody: ['name' => 'Alice']);
        $form->setRequest($request);

        $first = $form->validated();
        $second = $form->validated();

        self::assertSame($first, $second);
    }

    #[Test]
    public function queryParamsAreMergedWithBody(): void
    {
        $form = new class extends FormRequest {
            public function rules(): array
            {
                return [
                    'page' => 'required',
                    'name' => 'required',
                ];
            }
        };

        $request = $this->buildRequest(
            queryParams: ['page' => '1'],
            parsedBody: ['name' => 'Alice'],
        );
        $form->setRequest($request);

        $data = $form->validated();
        self::assertSame('1', $data['page']);
        self::assertSame('Alice', $data['name']);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed>|null $parsedBody
     */
    private function buildRequest(array $queryParams = [], ?array $parsedBody = null): ServerRequest
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: Uri::fromString('/test'),
            headers: [],
            body: Stream::create(''),
            serverParams: [],
        );

        if ($queryParams !== []) {
            $request = $request->withQueryParams($queryParams);
        }

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        return $request;
    }
}
