<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaCollation;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Database\Schema\TableIntrospector;

/**
 * The table that makes a declared Article 50 position a register.
 *
 * WHY THIS TABLE EXISTS WHEN THE OTHERS DID NOT NEED IT SPELLED OUT. Every store
 * in `20260828000001_create_ai_governance_records.php` backs an obligation the
 * digital omnibus deferred to 2 December 2027 or 2 August 2028. Article 50 was
 * not deferred: it has applied since 2 August 2026. The extension shipped durable
 * stores for the five deferred subsystems and left the one in force holding its
 * record in the memory of a single worker, so the obligation that binds today was
 * the only one whose record did not survive a restart.
 *
 * THE ARGUMENT THE IN-MEMORY STORE MAKES, AND WHERE IT STOPS. Its docblock says a
 * transparency policy is a declaration about how an application is built, fixed at
 * boot by whatever wires the application, and that a stale row is worse than no
 * row. That is true of a deployment that declares every surface in code on every
 * boot, and such a deployment should keep `transparency_store` set to `memory`.
 * It is not true of the contract: `AiTransparencyInterface::declare()` is public,
 * `#[Api]`, and says nothing about only being callable at boot, so a deployment
 * that lets an administrator declare a surface at runtime — which is the shape an
 * operator-facing governance console takes — loses that declaration at the next
 * restart, silently, with the compliance report then listing fewer surfaces than
 * the deployment actually declared. The choice is now made per deployment and
 * reported, instead of being made here for every deployment.
 *
 * KEYED BY SURFACE ID, matching {@see \Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency},
 * which keys its array the same way: re-declaring a surface replaces its position
 * rather than accumulating versions the contract has no way to tell apart.
 *
 * NO `declared_at` COLUMN, deliberately. {@see \Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy}
 * carries no instant, and a store that stamped its own clock would be producing a
 * fact about when a declaration was made that nothing measured — the defect
 * ADR-0050 names. The transparency subsystem already refuses to invent a
 * generation time for the same reason.
 *
 * BUILT THROUGH THE SCHEMA LAYER rather than DDL adapted per engine. Against what this file
 * used to emit: identifiers are quoted; MySQL creates the table COLLATE=utf8mb4_bin, so two
 * surface ids differing only in case stay two rows; booleans are BOOLEAN on PostgreSQL, not
 * SMALLINT. The table is guarded on its own existence.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $capabilities = new SchemaCapabilities($connection->driver(), $connection, $connection->variant());
        $schema = new SchemaManager($connection, new DdlCompiler($connection->driver(), $capabilities), $capabilities);
        $tables = new TableIntrospector($connection);

        // The disclosure is stored as its two components rather than as a JSON
        // document, because `AiInteractionDisclosure` refuses to exist without
        // both of them and a NULL notice beside a non-NULL locale is a row the
        // type cannot be built from. Two nullable columns make that corruption
        // visible to the hydrator; one JSON blob would hide it until the decode.
        if (!$tables->tableExists('ai_transparency_policies')) {
            $schema->createTable(new TableDefinition('ai_transparency_policies', [
                new SchemaColumn('surface_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('interacts_with_natural_persons', SchemaColumnType::Boolean),
                new SchemaColumn('disclosure_notice', SchemaColumnType::Text, nullable: true),
                new SchemaColumn('disclosure_locale', SchemaColumnType::String, nullable: true, length: 64),
                new SchemaColumn('generates', SchemaColumnType::Json),
                new SchemaColumn('exemption', SchemaColumnType::String, length: 64),
                new SchemaColumn('public_crime_reporting', SchemaColumnType::Boolean),
            ], collation: SchemaCollation::Exact));
        }
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS ai_transparency_policies');
    }
};
