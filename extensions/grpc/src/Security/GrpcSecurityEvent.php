<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Security;

use Pulsar\Api\Api;

/**
 * Security events emitted by the gRPC extension for audit logging.
 */
#[Api(since: '1.0.0')]
enum GrpcSecurityEvent: string
{
    /** Server reflection was enabled in a production environment. */
    case GrpcReflectionEnabled = 'grpc.reflection_enabled';

    /** Client certificate failed mTLS authentication (not in identity map). */
    case MtlsAuthenticationFailed = 'grpc.mtls_authentication_failed';

    /** An unknown SAN was presented by a client certificate. */
    case UnknownClientCertificate = 'grpc.unknown_client_certificate';

    /** A service identity was denied access to a gRPC method. */
    case GrpcAuthorizationDenied = 'grpc.authorization_denied';
}
