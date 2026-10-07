<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Handler;

use Pulsar\Api\Api;

/**
 * The type of an RPC method.
 * @api
 */
#[Api(since: '1.0.0')]
enum MethodType: string
{
    case Unary = 'unary';
    case ServerStreaming = 'server_streaming';
    case ClientStreaming = 'client_streaming';
    case BidirectionalStreaming = 'bidirectional_streaming';

    /**
     * Whether this method type involves client-side streaming.
     */
    public function hasClientStream(): bool
    {
        return $this === self::ClientStreaming || $this === self::BidirectionalStreaming;
    }

    /**
     * Whether this method type involves server-side streaming.
     */
    public function hasServerStream(): bool
    {
        return $this === self::ServerStreaming || $this === self::BidirectionalStreaming;
    }
}
