<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Htmx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Htmx\HtmxResponse;
use Pulsar\Http\Htmx\SwapStrategy;
use Pulsar\Http\ResponseStatus;

#[CoversClass(HtmxResponse::class)]
final class HtmxResponseTest extends TestCase
{
    #[Test]
    public function fragment_creates_html_response(): void
    {
        $response = HtmxResponse::fragment('<p>Hello</p>')->toResponse();

        self::assertSame('<p>Hello</p>', $response->body);
        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function fragment_with_custom_status(): void
    {
        $response = HtmxResponse::fragment('<p>Created</p>', ResponseStatus::Created)->toResponse();

        self::assertSame(ResponseStatus::Created, $response->status);
    }

    #[Test]
    public function with_swap_sets_reswap_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withSwap(SwapStrategy::OuterHTML)
            ->toResponse();

        self::assertSame('outerHTML', $response->headers->first('PX-Reswap'));
    }

    #[Test]
    public function with_target_sets_retarget_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withTarget('#container')
            ->toResponse();

        self::assertSame('#container', $response->headers->first('PX-Retarget'));
    }

    #[Test]
    public function with_push_url_sets_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withPushUrl('/new-page')
            ->toResponse();

        self::assertSame('/new-page', $response->headers->first('PX-Push-Url'));
    }

    #[Test]
    public function with_replace_url_sets_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withReplaceUrl('/replaced')
            ->toResponse();

        self::assertSame('/replaced', $response->headers->first('PX-Replace-Url'));
    }

    #[Test]
    public function with_redirect_sets_header(): void
    {
        $response = HtmxResponse::fragment('')
            ->withRedirect('/login')
            ->toResponse();

        self::assertSame('/login', $response->headers->first('PX-Redirect'));
    }

    #[Test]
    public function with_refresh_sets_header(): void
    {
        $response = HtmxResponse::fragment('')
            ->withRefresh()
            ->toResponse();

        self::assertSame('true', $response->headers->first('PX-Refresh'));
    }

    #[Test]
    public function with_reswap_overrides_swap(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withReswap('innerHTML swap:1s')
            ->toResponse();

        self::assertSame('innerHTML swap:1s', $response->headers->first('PX-Reswap'));
    }

    #[Test]
    public function trigger_events_encoded_as_comma_separated_for_simple(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withTrigger('closeModal')
            ->withTrigger('refreshList')
            ->toResponse();

        self::assertSame('closeModal, refreshList', $response->headers->first('PX-Trigger'));
    }

    #[Test]
    public function trigger_events_encoded_as_json_with_details(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withTrigger('showMessage', ['text' => 'Saved!'])
            ->toResponse();

        $trigger = $response->headers->first('PX-Trigger');
        self::assertNotNull($trigger);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($trigger, true);
        self::assertSame(['text' => 'Saved!'], $decoded['showMessage']);
    }

    #[Test]
    public function trigger_after_settle_sets_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withTriggerAfterSettle('highlight')
            ->toResponse();

        self::assertSame('highlight', $response->headers->first('PX-Trigger-After-Settle'));
    }

    #[Test]
    public function trigger_after_swap_sets_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')
            ->withTriggerAfterSwap('scrollTo')
            ->toResponse();

        self::assertSame('scrollTo', $response->headers->first('PX-Trigger-After-Swap'));
    }

    #[Test]
    public function immutability_preserved(): void
    {
        $original = HtmxResponse::fragment('<p>A</p>');
        $modified = $original->withTarget('#other');

        $originalResponse = $original->toResponse();
        $modifiedResponse = $modified->toResponse();

        self::assertFalse($originalResponse->headers->has('PX-Retarget'));
        self::assertSame('#other', $modifiedResponse->headers->first('PX-Retarget'));
    }

    #[Test]
    public function default_swap_does_not_set_reswap_header(): void
    {
        $response = HtmxResponse::fragment('<p>Hi</p>')->toResponse();

        self::assertFalse($response->headers->has('PX-Reswap'));
    }
}
