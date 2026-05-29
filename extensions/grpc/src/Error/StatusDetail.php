<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Error;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Rich error details following the google.rpc.Status model.
 *
 * Provides structured error information beyond the status code and message,
 * allowing services to communicate machine-readable error details.
 *
 * @see https://cloud.google.com/apis/design/errors#error_model
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StatusDetail
{
    /**
     * @param GrpcStatus           $code    The gRPC status code
     * @param string               $message Human-readable error description
     * @param array<string, mixed> $details Additional structured error details
     */
    public function __construct(
        public GrpcStatus $code,
        public string $message,
        public array $details = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $codeValue = Coerce::strictInt($data['code'] ?? null, GrpcStatus::Unknown->value);
        $details = $data['details'] ?? null;
        /** @var array<string, mixed> $detailsArr */
        $detailsArr = is_array($details) ? $details : [];

        return new self(
            code: GrpcStatus::tryFrom($codeValue) ?? GrpcStatus::Unknown,
            message: Coerce::string($data['message'] ?? null),
            details: $detailsArr,
        );
    }

    /**
     * @return array{code: int, message: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
