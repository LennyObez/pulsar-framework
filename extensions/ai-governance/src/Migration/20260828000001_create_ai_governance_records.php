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
 * The tables that make an AI management system record survive a restart.
 *
 * Until this migration the extension shipped four in-memory stores and nothing
 * else, so thirteen ISO 42001 controls rested on a model inventory, an impact
 * assessment, a provenance record and an explanation that all evaporated when
 * the worker recycled. Clause 7.5 asks for documented information and Clause 9.1
 * asks for retained evidence of monitoring results; neither is discharged by a
 * PHP array.
 *
 * KEYS ARE THE DTO's OWN IDENTIFIERS, everywhere it declares one. `AiModel->id`,
 * `DataProvenance->id`, `ImpactFinding->id`, `Explanation->decisionId` and
 * `DataQualityReport->datasetId` are each documented as unique in their own
 * docblock, so making them primary keys states that documented uniqueness in the
 * schema rather than leaving it as prose the store may or may not honour. The one
 * table with a surrogate key is `ai_monitoring_records`, because a monitoring
 * result is an event and two runs of the same hook against the same model are two
 * pieces of evidence rather than one being an update of the other.
 *
 * BUILT THROUGH THE SCHEMA LAYER rather than DDL adapted per engine. Against what this file
 * used to emit: identifiers are quoted; MySQL creates every table COLLATE=utf8mb4_bin, so two
 * keys differing only in case stay two rows; booleans are BOOLEAN on PostgreSQL, not
 * SMALLINT; instants are DATETIME(6) on MySQL, TIMESTAMP on PostgreSQL and DATETIME on SQLite,
 * written as StoredValue::INSTANT_FORMAT (MySQL reads back a '.000000' StoredValue accepts,
 * SQLite's NUMERIC affinity leaves the spelling as text); scores stay 8-byte DOUBLE; index
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

        // The AI system inventory (Clause 8.2, Clause 7.5). `previous_status` is
        // carried so a durable registry can answer the rollback question the
        // in-memory one answers from a second array; without it a restart between
        // a transition and its rollback loses where the model came from.
        if (!$tables->tableExists('ai_models')) {
            $schema->createTable(new TableDefinition('ai_models', [
                new SchemaColumn('id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('name', SchemaColumnType::String, length: 255),
                new SchemaColumn('version', SchemaColumnType::String, length: 64),
                new SchemaColumn('provider', SchemaColumnType::String, length: 191),
                new SchemaColumn('type', SchemaColumnType::String, length: 64),
                new SchemaColumn('risk_level', SchemaColumnType::String, length: 32),
                new SchemaColumn('status', SchemaColumnType::String, length: 32),
                new SchemaColumn('previous_status', SchemaColumnType::String, nullable: true, length: 32),
                new SchemaColumn('card', SchemaColumnType::Json, nullable: true),
                new SchemaColumn('registered_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        $indexes->ensure('ai_models', 'idx_ai_models_status', ['status']);

        $indexes->ensure('ai_models', 'idx_ai_models_risk_level', ['risk_level']);

        // Clause 6.1.2 and 8.2. The assessment row exists so that "assessed, and
        // nothing adverse was found" is a different record from "never assessed" —
        // the distinction `hasAssessment()` is documented to draw and that a
        // findings table alone cannot express.
        if (!$tables->tableExists('ai_impact_assessments')) {
            $schema->createTable(new TableDefinition('ai_impact_assessments', [
                new SchemaColumn('model_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('categories', SchemaColumnType::Json),
                new SchemaColumn('assessed_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        if (!$tables->tableExists('ai_impact_findings')) {
            $schema->createTable(new TableDefinition('ai_impact_findings', [
                new SchemaColumn('model_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('finding_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('category', SchemaColumnType::String, length: 32),
                new SchemaColumn('severity', SchemaColumnType::String, length: 32),
                new SchemaColumn('title', SchemaColumnType::String, length: 255),
                new SchemaColumn('description', SchemaColumnType::Text),
                new SchemaColumn('recommendation', SchemaColumnType::Text),
                new SchemaColumn('recorded_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        // Clause 8.3 and Annex A.5: provenance, quality and the basis on which the
        // data was obtained.
        if (!$tables->tableExists('ai_data_provenance')) {
            $schema->createTable(new TableDefinition('ai_data_provenance', [
                new SchemaColumn('id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('dataset_id', SchemaColumnType::String, length: 191),
                new SchemaColumn('source', SchemaColumnType::Text),
                new SchemaColumn('data_type', SchemaColumnType::String, length: 64),
                new SchemaColumn('collected_at', SchemaColumnType::DateTime),
                new SchemaColumn('consent_obtained', SchemaColumnType::Boolean),
                new SchemaColumn('consent_reference', SchemaColumnType::String, nullable: true, length: 191),
                new SchemaColumn('license', SchemaColumnType::String, nullable: true, length: 191),
                new SchemaColumn('transformations', SchemaColumnType::Json),
                new SchemaColumn('quality_metrics', SchemaColumnType::Json),
            ], collation: SchemaCollation::Exact));
        }

        $indexes->ensure('ai_data_provenance', 'idx_ai_data_provenance_dataset', ['dataset_id']);

        if (!$tables->tableExists('ai_data_quality_reports')) {
            $schema->createTable(new TableDefinition('ai_data_quality_reports', [
                new SchemaColumn('dataset_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('assessed_at', SchemaColumnType::DateTime),
                new SchemaColumn('completeness', SchemaColumnType::Double),
                new SchemaColumn('accuracy', SchemaColumnType::Double),
                new SchemaColumn('consistency', SchemaColumnType::Double),
                new SchemaColumn('total_records', SchemaColumnType::Integer),
                new SchemaColumn('invalid_records', SchemaColumnType::Integer),
                new SchemaColumn('issues', SchemaColumnType::Json),
            ], collation: SchemaCollation::Exact));
        }

        // Clause 8.4 and Annex A.8.5: the explanation a person affected by a
        // decision is entitled to, held somewhere they can still be given it.
        if (!$tables->tableExists('ai_explanations')) {
            $schema->createTable(new TableDefinition('ai_explanations', [
                new SchemaColumn('decision_id', SchemaColumnType::String, primaryKey: true, length: 191),
                new SchemaColumn('model_id', SchemaColumnType::String, length: 191),
                new SchemaColumn('summary', SchemaColumnType::Text),
                new SchemaColumn('factors', SchemaColumnType::Json),
                new SchemaColumn('confidence', SchemaColumnType::Double),
                new SchemaColumn('alternatives_considered', SchemaColumnType::Json),
                new SchemaColumn('generated_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        $indexes->ensure('ai_explanations', 'idx_ai_explanations_model', ['model_id']);

        // Clause 9.1's closing sentence: retained documented information as
        // evidence of the monitoring results.
        if (!$tables->tableExists('ai_monitoring_records')) {
            $schema->createTable(new TableDefinition('ai_monitoring_records', [
                new SchemaColumn('id', SchemaColumnType::BigInt, primaryKey: true, autoIncrement: true),
                new SchemaColumn('model_id', SchemaColumnType::String, length: 191),
                new SchemaColumn('hook_name', SchemaColumnType::String, length: 191),
                new SchemaColumn('healthy', SchemaColumnType::Boolean),
                new SchemaColumn('message', SchemaColumnType::Text),
                new SchemaColumn('metrics', SchemaColumnType::Json),
                new SchemaColumn('observed_at', SchemaColumnType::DateTime),
            ], collation: SchemaCollation::Exact));
        }

        $indexes->ensure('ai_monitoring_records', 'idx_ai_monitoring_model_observed', ['model_id', 'observed_at']);
    }

    public function down(ConnectionInterface $connection): void
    {
        // Dropped in the reverse of creation order. None of these tables holds a
        // foreign key — the governance record has to be able to describe a model
        // that was removed from the inventory, which is exactly the history an
        // audit asks for — so the order is for readability rather than for the
        // engine.
        $connection->execute('DROP TABLE IF EXISTS ai_monitoring_records');
        $connection->execute('DROP TABLE IF EXISTS ai_explanations');
        $connection->execute('DROP TABLE IF EXISTS ai_data_quality_reports');
        $connection->execute('DROP TABLE IF EXISTS ai_data_provenance');
        $connection->execute('DROP TABLE IF EXISTS ai_impact_findings');
        $connection->execute('DROP TABLE IF EXISTS ai_impact_assessments');
        $connection->execute('DROP TABLE IF EXISTS ai_models');
    }
};
