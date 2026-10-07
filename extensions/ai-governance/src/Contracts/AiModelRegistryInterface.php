<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

/**
 * Registry for AI models under governance.
 *
 * ISO 42001:2023 Clause 8.2 requires organizations to identify, document,
 * and track all AI systems within scope. This registry provides the central
 * catalog of registered models with lifecycle and risk tracking.
 * @api
 */
#[Api(since: '1.0.0')]
interface AiModelRegistryInterface
{
    /**
     * Register a new AI model in the governance registry.
     *
     * Implementations must refuse a model that is already in production status
     * and classified `AiModelRiskLevel::Unacceptable`: EU AI Act
     * (Regulation (EU) 2024/1689) Article 5 prohibits the practice outright, and
     * a registry that accepted one would be recording a prohibited system as
     * live.
     *
     * That refusal does not generalise to `AiModelRiskLevel::High`. Recording a
     * high-risk system that is already running — with its Article 9, 11 and 72
     * obligations still unmet — is what an inventory is for, and a registry that
     * refused the record could not govern the system it was built to govern. The
     * asymmetry follows the Act: Article 5 bans the practice outright, while a
     * high-risk system is permitted subject to conditions, and an unmet
     * condition is a gap to be recorded rather than a fact to be denied.
     * Authorising a *transition* into production is a different operation, and
     * it runs the deployment gates of
     * {@see AiLifecycleManagerInterface::deploy()}.
     *
     * @throws InvalidArgumentException If the model is a prohibited practice already in production
     */
    public function register(AiModel $model): void;

    /**
     * Retrieve a model by its unique identifier.
     */
    #[NoDiscard]
    public function get(string $modelId): ?AiModel;

    /**
     * Transition a model to a new lifecycle status.
     *
     * Implementations must refuse a transition to `AiModelStatus::Production`
     * for a model classified `AiModelRiskLevel::Unacceptable`, whatever the
     * status state machine would otherwise allow. This is the invariant the
     * deployment gates exist to keep, held here too because this method is
     * reachable without them.
     *
     * @throws InvalidArgumentException If the model is not found, the transition is invalid,
     *                                  or the model is a prohibited practice bound for production
     */
    public function transitionStatus(string $modelId, AiModelStatus $newStatus): AiModel;

    /**
     * Update the risk level classification of a model.
     *
     * Reclassifying a model that is in production as
     * `AiModelRiskLevel::Unacceptable` withdraws it from production in the same
     * operation — the returned model carries the new classification and a status
     * that is no longer production. The alternative, refusing the update, would
     * leave a prohibited system live and its classification unrecorded.
     *
     * The return value is not optional to read: it is the only place the caller
     * learns that the model it reclassified is no longer serving traffic.
     * PHP does not inherit `#[NoDiscard]` from an interface onto an implementing
     * method, so the attribute below documents the contract while each
     * implementation must repeat it to make discarding the withdrawal diagnose.
     *
     * @throws InvalidArgumentException If the model is not found
     */
    #[NoDiscard]
    public function updateRiskLevel(string $modelId, AiModelRiskLevel $riskLevel): AiModel;

    /**
     * Return all registered models.
     *
     * @return array<string, AiModel> Keyed by model ID
     */
    #[NoDiscard]
    public function all(): array;

    /**
     * Return models filtered by lifecycle status.
     *
     * @return list<AiModel>
     */
    #[NoDiscard]
    public function byStatus(AiModelStatus $status): array;

    /**
     * Return models filtered by risk level.
     *
     * @return list<AiModel>
     */
    #[NoDiscard]
    public function byRiskLevel(AiModelRiskLevel $riskLevel): array;
}
