<?php

declare(strict_types=1);

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\IndexOperations;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaCollation;
use Pulsar\Database\Schema\SchemaColumn;
use Pulsar\Database\Schema\SchemaColumnType;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Database\Schema\TableDefinition;
use Pulsar\Database\Schema\TableIntrospector;

/**
 * The role a deployment holds, and the people who oversee its AI systems.
 *
 * TWO GAPS, ONE MIGRATION, because the second cannot be enforced without the first.
 *
 * `ai_models.actor_role` is the column the extension had no way to express. The
 * EU AI Act attaches its duties to a person in a role with respect to a system,
 * not to the system: a provider (Article 3(3)) operates the risk management
 * system of Article 9, holds the Annex IV documentation of Article 11 and carries
 * the Article 72(3) monitoring plan; a deployer (Article 3(4)) does none of those
 * and instead owes Article 26 — use per the instructions, human oversight
 * assigned to competent natural persons, input data governance, monitoring, and
 * six months of retained logs. Without the column, the high-risk deployment gate
 * demanded the provider artefacts from every deployment, which is right for a
 * provider and wrong twice over for a bank running a bought-in credit model.
 *
 * NULLABLE, and that is a decision rather than a convenience. A role that has not
 * been declared is ABSENT, and absence is not a value: the gate refuses a
 * high-risk deployment whose role is absent and names both sets, rather than
 * assuming one. A column defaulted to 'provider' would have turned "nobody
 * decided" into a decision, on every row that already existed.
 *
 * `ai_oversight_assignments` and `ai_oversight_interventions` are Article 14 and
 * Article 26(2), which the extension modelled nowhere at all. They are two tables
 * because they hold two kinds of fact. An assignment is a CURRENT state — this
 * person oversees this system, on this stated competence and this stated
 * authority — replaced when re-assigned and deleted when withdrawn. An
 * intervention is an EVENT, and nothing deletes one: what a person did while they
 * held the authority happened, and a register that emptied when a mandate was
 * revoked would be evidence of nothing.
 *
 * NO CLOCK IS TAKEN BY EITHER TABLE. `assigned_at` and `occurred_at` are handed
 * in by whatever made the assignment or observed the action, the same rule the
 * rest of this extension follows: a row stamped at write time records when it was
 * written down, not when the person was assigned or acted.
 *
 * BUILT THROUGH THE SCHEMA LAYER rather than DDL adapted per engine. Against what this file
 * used to emit: identifiers are quoted; MySQL creates both tables COLLATE=utf8mb4_bin;
 * instants are DATETIME(6) on MySQL, TIMESTAMP on PostgreSQL and DATETIME on SQLite; index
 * names are unchanged. Each table is guarded on its own existence and each index created
 * through IndexOperations::ensure(), so a run that died part-way completes on the next.
 */
return new class implements MigrationInterface {
    public function up(ConnectionInterface $connection): void
    {
        $capabilities = new SchemaCapabilities($connection->driver(), $connection, $connection->variant());
        $schema = new SchemaManager($connection, new DdlCompiler($connection->driver(), $capabilities), $capabilities);
        $tables = new TableIntrospector($connection);
        $indexes = new IndexOperations($connection);

        // Guarded, because a migration is recorded only once `up()` returns: one
        // that dies after this statement runs again from the top, and a second
        // ADD COLUMN would fail on every engine.
        if (!$tables->columnExists('ai_models', 'actor_role')) {
            $schema->addColumn('ai_models', new SchemaColumn('actor_role', SchemaColumnType::String, nullable: true, length: 64));
        }

        // Article 26(2): oversight assigned to named natural persons with the
        // necessary competence, training and authority. The two bases are stored
        // as the sentences they are rather than as flags — there is no way to
        // satisfy the type without having written down what makes the person
        // competent and under what mandate they may act — and `capabilities`
        // holds the Article 14(4) capacities the assignment confers, of which the
        // two that Article 14(4)(d) and (e) require to be exercisable are
        // mandatory in the DTO.
        //
        // Keyed by (model_id, overseer_id): a person holds one set of capacities
        // over one system, so re-assigning them replaces the arrangement rather
        // than leaving a reader to guess which of two rows is in force.
        if (!$tables->tableExists('ai_oversight_assignments')) {
            $schema->createTable(new TableDefinition('ai_oversight_assignments', [
                new SchemaColumn('model_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('overseer_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('competence_basis', SchemaColumnType::Text),
                new SchemaColumn('authority_basis', SchemaColumnType::Text),
                new SchemaColumn('capabilities', SchemaColumnType::Json),
                new SchemaColumn('assigned_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        // Article 14(4)(d) and (e), exercised. The only artefact in this
        // subsystem that is evidence rather than intent: an arrangement nobody
        // has ever used is a policy, and this is the register that shows someone
        // did disregard, reverse or halt something.
        //
        // `decision_id` is nullable and the null is meaningful: an interruption of
        // the system as a whole answers no single decision. Where it is set it
        // joins this record to the `ai_explanations` row for that decision, so a
        // contested decision and the human override of it are one story.
        if (!$tables->tableExists('ai_oversight_interventions')) {
            $schema->createTable(new TableDefinition('ai_oversight_interventions', [
                new SchemaColumn('intervention_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('model_id', SchemaColumnType::String, length: 191),
                new SchemaColumn('overseer_id', SchemaColumnType::String, length: 191),
                new SchemaColumn('action', SchemaColumnType::String, length: 64),
                new SchemaColumn('rationale', SchemaColumnType::Text),
                new SchemaColumn('decision_id', SchemaColumnType::String, nullable: true, length: 191),
                new SchemaColumn('occurred_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        // The register is read per system, oldest first, which is exactly how an
        // assessor asks for it.
        $indexes->ensure('ai_oversight_interventions', 'idx_ai_oversight_model_occurred', ['model_id', 'occurred_at']);

        // And per person, for the question an investigation asks: what has this
        // overseer done.
        $indexes->ensure('ai_oversight_interventions', 'idx_ai_oversight_overseer', ['overseer_id']);
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS ai_oversight_interventions');
        $connection->execute('DROP TABLE IF EXISTS ai_oversight_assignments');

        // Guarded on the way down too, so a `down()` re-run over a schema that
        // already lost the column does not fail. SQLite has supported DROP COLUMN
        // since 3.35 (2021), comfortably below anything a PHP 8.5 runtime ships
        // against.
        if (new TableIntrospector($connection)->columnExists('ai_models', 'actor_role')) {
            $connection->execute('ALTER TABLE ai_models DROP COLUMN actor_role');
        }
    }
};
