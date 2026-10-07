<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Web;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Error\GrpcStatus;

use function chr;
use function pack;
use function sprintf;
use function strlen;

/**
 * Response DTO for gRPC-Web requests.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GrpcWebResponse
{
    public function __construct(
        public string $body,
        public GrpcStatus $status,
        public string $contentType = 'application/grpc-web+proto',
        public int $httpStatus = 200,
    ) {}

    /**
     * Create an error response.
     */
    public static function error(GrpcStatus $status, string $message = ''): self
    {
        // Build a trailers-only response for errors
        $trailerString = sprintf("grpc-status:%d\r\n", $status->value);

        if ($message !== '') {
            $trailerString .= sprintf("grpc-message:%s\r\n", $message);
        }

        // Trailers frame: flag=0x80, length, data
        $body = chr(0x80) . pack('N', strlen($trailerString)) . $trailerString;

        return new self(
            body: $body,
            status: $status,
            contentType: 'application/grpc-web+proto',
            httpStatus: $status->httpStatusCode(),
        );
    }

    /**
     * Whether this response indicates success.
     */
    public function isOk(): bool
    {
        return $this->status->isOk();
    }

    /**
     * Build HTTP response headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => $this->contentType,
            'X-Grpc-Web' => 'true',
        ];
    }
}
