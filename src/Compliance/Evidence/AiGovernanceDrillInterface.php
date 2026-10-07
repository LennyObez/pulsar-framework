<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Throwable;

/**
 * The seam through which the assessor exercises a deployment's AI management
 * system record and its monitoring.
 *
 * WHY A PORT AND NOT THE CONTRACTS THEMSELVES. Identical to
 * {@see AiTransparencyDrillInterface}, and for the identical reason:
 * `AiModelRegistryInterface`, `AiImpactAssessmentInterface`,
 * `AiDataGovernanceInterface`, `ExplainabilityInterface`,
 * `AiLifecycleManagerInterface` and `MonitoringRecordStoreInterface` all belong
 * to `pulsar/ai-governance`, an OPTIONAL package at trust tier `verified` and
 * kind `product`, absent from the root autoload by ADR-0004 and named nowhere in
 * `src/`. The framework declares what it needs to be able to run, the extension
 * implements it, and the composition root hands the implementation over.
 *
 * WHAT THIS PORT MEASURES THAT NOTHING ELSE COULD. The AI governance facts in
 * this vocabulary were all RESOLUTIONS — which class answered which contract —
 * and the gatherer's own table records four of the shipped answers as inert
 * because they are in-memory stores. That table is a hard-coded list of class
 * names, so it grades a store by recognising it, and it says nothing at all
 * about a store it has not been told about. This port replaces recognition with
 * behaviour: something is written, and then read back **through a store instance
 * that shares no memory with the one that wrote it**. A record that lives only in
 * the writer's process is not found on that path, whatever the class is called.
 *
 * ON `fresh_instance`. Every read below reports whether the implementation was
 * able to construct that second instance. It can for the stores the extension
 * ships; it cannot for a store an operator configured by class name, because
 * nothing here knows how to build one. That boolean is raw material, not a
 * verdict — {@see AiGovernanceRecordObserver} decides what a read through the
 * original instance is worth, and the answer is "it does not establish
 * durability", because a read that the writer's own memory can serve proves only
 * that the writer has memory.
 *
 * IT MARSHALS AND IT DOES NOT JUDGE. Every method hands back the record as the
 * subsystem stored it. Nothing returns a status, an outcome, or a boolean meaning
 * "that worked" — the one boolean that exists says what the implementation DID,
 * not whether the result was good. Every comparison that decides a compliance
 * fact is made inside `src/Compliance/Evidence/`, which
 * {@see \Pulsar\Compliance\Control\MeasuringComponent} seals, so the worst an
 * adapter can do is forge the material an assessor would ask to see rather than
 * assert a conclusion the vocabulary would carry for it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface AiGovernanceDrillInterface
{
    /**
     * What this deployment configured each store to be, verbatim.
     *
     * The operator's own words — `database`, `memory`, or a class name — and not
     * a judgement about them. It is reported alongside every result so a reader
     * of the report can see WHY a record did or did not survive rather than only
     * that it did not.
     *
     * @return array{
     *     registry: string,
     *     impact_assessment: string,
     *     data_governance: string,
     *     explainability: string,
     *     monitoring_records: string,
     * }
     */
    #[NoDiscard]
    public function configuredStores(): array;

    /**
     * Put one model into the inventory, or replace it if the id is already there.
     *
     * @param non-empty-string $modelId      A reserved id; see {@see AiGovernanceRecordObserver::PROBE_MODEL}
     * @param non-empty-string $name
     * @param non-empty-string $version
     * @param non-empty-string $provider
     * @param non-empty-string $type
     * @param non-empty-string $riskLevel    The tier, as the extension spells it
     * @param non-empty-string $status       The lifecycle status, as the extension spells it
     * @param int              $registeredAt Unix seconds; handed in so a registry that
     *                                       stamps its own clock is visible on the way back
     *
     * @throws Throwable when the registry refuses the record; the refusal is the measurement
     */
    public function recordModel(
        string $modelId,
        string $name,
        string $version,
        string $provider,
        string $type,
        string $riskLevel,
        string $status,
        int $registeredAt,
    ): void;

    /**
     * Read that model back through a second store instance.
     *
     * @return array{
     *     fresh_instance: bool,
     *     store: string,
     *     inventory_size: int,
     *     record: null|array{
     *         id: string,
     *         name: string,
     *         version: string,
     *         provider: string,
     *         type: string,
     *         risk_level: string,
     *         status: string,
     *         registered_at: int,
     *     },
     * }
     */
    #[NoDiscard]
    public function readModelBack(string $modelId): array;

    /**
     * Open an impact assessment against a model, recording that it was assessed.
     *
     * @param non-empty-string $modelId
     *
     * @throws Throwable when the store refuses
     */
    public function openAssessment(string $modelId): void;

    /**
     * Read the assessment back through a second store instance.
     *
     * Takes the same non-empty id {@see openAssessment()} takes, and for the same
     * reason: the id is handed straight to a store whose contract requires one, and
     * an empty model id addresses no model in any of them.
     *
     * @param non-empty-string $modelId
     *
     * @return array{fresh_instance: bool, store: string, assessed: bool, findings: int, risk_score: float}
     */
    #[NoDiscard]
    public function readAssessmentBack(string $modelId): array;

    /**
     * Record a data quality report for a dataset.
     *
     * The quality report is what this drill writes into data governance, and
     * provenance is deliberately what it does not: a report is keyed by dataset
     * and replaced on rewrite, while provenance accumulates one row per record
     * and `AiDataGovernanceInterface` has no withdrawal. Writing provenance would
     * grow the deployment's governance record by a row per compliance report
     * forever, and the report proves the same store holds the same data.
     *
     * @param non-empty-string $datasetId
     *
     * @throws Throwable when the store refuses
     */
    public function recordQualityReport(
        string $datasetId,
        int $assessedAt,
        float $completeness,
        float $accuracy,
        float $consistency,
        int $totalRecords,
        int $invalidRecords,
    ): void;

    /**
     * Read that quality report back through a second store instance.
     *
     * @return array{
     *     fresh_instance: bool,
     *     store: string,
     *     report: null|array{
     *         dataset_id: string,
     *         assessed_at: int,
     *         completeness: float,
     *         accuracy: float,
     *         consistency: float,
     *         total_records: int,
     *         invalid_records: int,
     *     },
     * }
     */
    #[NoDiscard]
    public function readQualityReportBack(string $datasetId): array;

    /**
     * Record one explanation, carrying exactly one decision factor.
     *
     * The factor is not decoration. An explanation whose summary survives and
     * whose factors do not is an explanation that cannot answer "why", which is
     * the whole of what GDPR Article 22(3) and ISO 42001 Annex A.8.5 ask for, and
     * a store that flattened its structured half would pass a check that only
     * looked at the prose.
     *
     * @param non-empty-string $decisionId
     * @param non-empty-string $modelId
     * @param non-empty-string $summary
     * @param non-empty-string $factorName
     * @param non-empty-string $factorDescription
     *
     * @throws Throwable when the store refuses
     */
    public function recordExplanation(
        string $decisionId,
        string $modelId,
        string $summary,
        float $confidence,
        int $generatedAt,
        string $factorName,
        float $factorWeight,
        string $factorDescription,
    ): void;

    /**
     * Read that explanation back through a second store instance.
     *
     * Takes the same non-empty id {@see recordExplanation()} takes: it is handed
     * straight to a store whose contract requires one, and an empty decision id
     * addresses no decision in any of them.
     *
     * @param non-empty-string $decisionId
     *
     * @return array{
     *     fresh_instance: bool,
     *     store: string,
     *     by_model: int,
     *     explanation: null|array{
     *         decision_id: string,
     *         model_id: string,
     *         summary: string,
     *         confidence: float,
     *         generated_at: int,
     *         factors: list<array{name: string, weight: float, description: string}>,
     *     },
     * }
     */
    #[NoDiscard]
    public function readExplanationBack(string $decisionId): array;

    /**
     * Run every registered monitoring hook against one model.
     *
     * The hooks this deployment holds are named by the results rather than by a
     * separate enumeration, deliberately: a list of registered hooks says which
     * hooks exist and a list of results says which hooks RAN, and the difference
     * between those two is the whole reason `MonitoringHookInterface` having no
     * implementation went unnoticed for as long as it did.
     *
     * @param non-empty-string $modelId
     *
     * @return list<array{hook: string, healthy: bool, message: string, metrics: array<string, mixed>}>
     *
     * @throws Throwable when the model is unknown or a hook raises
     */
    #[NoDiscard]
    public function runMonitoring(string $modelId): array;

    /**
     * Read the retained monitoring records back through a second store instance.
     *
     * @param non-empty-string $modelId
     *
     * @return array{
     *     fresh_instance: bool,
     *     store: string,
     *     retained: int,
     *     records: list<array{hook: string, healthy: bool, message: string, observed_at: int}>,
     * }
     */
    #[NoDiscard]
    public function readRetainedMonitoringBack(string $modelId): array;

    /**
     * Remove every retained monitoring record for one model, and say how many went.
     *
     * This is how the monitoring measurement pays for its own write. Unlike a
     * declaration or an inventory entry — both keyed, both replaced on the next
     * run — a monitoring record is an event and a second run appends a second
     * one, so without removal a compliance report would grow the deployment's
     * monitoring history by a row per run about a model that serves no traffic.
     * It is scoped to one model precisely so it cannot empty the register.
     *
     * @param non-empty-string $modelId
     *
     * @throws Throwable when the store refuses
     */
    #[NoDiscard]
    public function purgeRetainedMonitoring(string $modelId): int;
}
