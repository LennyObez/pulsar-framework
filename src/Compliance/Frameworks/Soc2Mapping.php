<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers SOC 2 Trust Services Criteria controls into the catalog.
 *
 * Maps Pulsar framework features to the SOC 2 controls they provide coverage for.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Soc2Mapping
{
    /**
     * Register SOC 2 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'CC1.1',
            framework: 'soc2',
            title: 'COSO Principle 1: Integrity and Ethical Values',
            description: 'The entity demonstrates a commitment to integrity and ethical values through '
                . 'comprehensive audit logging that captures all security-relevant operations with '
                . 'tamper-evident chains.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'hmac_chain'],
        ));

        $catalog->register(new Control(
            id: 'CC6.1',
            framework: 'soc2',
            title: 'Logical and Physical Access Controls',
            description: 'The entity implements logical access security software, infrastructure, and '
                . 'architectures over protected information assets to protect them from security events. '
                . 'Covered by authentication middleware, session management, and authorization policies.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'authorization', 'session_management'],
        ));

        $catalog->register(new Control(
            id: 'CC6.3',
            framework: 'soc2',
            title: 'Role-Based Access Control',
            description: 'The entity authorizes, modifies, or removes access to data, software, functions, '
                . 'and other protected information assets based on roles and responsibilities. '
                . 'Covered by the RBAC subsystem and permission gates.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['rbac', 'permission_gates', 'authorization'],
        ));

        $catalog->register(new Control(
            id: 'CC7.2',
            framework: 'soc2',
            title: 'System Monitoring',
            description: 'The entity monitors system components and the operation of those components for '
                . 'anomalies that are indicative of malicious acts, natural disasters, and errors affecting '
                . 'the entity\'s ability to meet its objectives. Covered by metrics collection, health '
                . 'checks, and observability instrumentation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['observability', 'metrics', 'health_checks'],
        ));

        $catalog->register(new Control(
            id: 'CC8.1',
            framework: 'soc2',
            title: 'Change Management',
            description: 'The entity authorizes, designs, develops or acquires, configures, documents, tests, '
                . 'approves, and implements changes to infrastructure, data, software, and procedures to '
                . 'meet its objectives. Covered by deployment pipelines and integrity verification.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['deployment', 'integrity_verification', 'extension_signatures'],
        ));
    }
}
