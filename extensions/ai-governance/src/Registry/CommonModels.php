<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Registry;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

/**
 * Pre-registered AI model definitions for commonly deployed models.
 *
 * Provides factory methods that return pre-filled AiModel instances with
 * ModelCard documentation. These satisfy ISO 42001:2023 Clause 8.2 model
 * registration requirements out of the box for popular providers.
 *
 * Usage:
 *   $registry->register(CommonModels::claudeSonnet());
 *
 * @psalm-api Public factory class — methods are called by user-land
 *            governance bootstrap code, not by framework internals.
 * @api
 */
#[Api(since: '1.0.0')]
final class CommonModels
{
    #[NoDiscard]
    public static function gpt4o(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'openai-gpt-4o',
            name: 'GPT-4o',
            version: '2024-08-06',
            provider: 'OpenAI',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Multimodal large language model capable of text, image, and audio processing.',
                intendedUse: 'General-purpose text generation, analysis, code assistance, and multimodal reasoning.',
                capabilities: [
                    'Natural language understanding and generation',
                    'Code generation and analysis',
                    'Image understanding',
                    'Structured data extraction',
                ],
                limitations: [
                    'Knowledge cutoff limits real-time awareness',
                    'May generate plausible but incorrect outputs (hallucination)',
                    'Limited context window affects long-document processing',
                ],
                knownBiases: [
                    'Training data reflects internet-scale English-language bias',
                    'May underperform on low-resource languages',
                ],
                trainingDataSources: ['Publicly available internet text', 'Licensed datasets'],
                ethicalConsiderations: [
                    'Output should be reviewed before use in regulated decisions',
                    'Not suitable as sole decision-maker for consequential outcomes',
                ],
            ),
        );
    }

    #[NoDiscard]
    public static function claudeOpus(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'anthropic-claude-opus',
            name: 'Claude Opus 4',
            version: '2025-05-14',
            provider: 'Anthropic',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Advanced reasoning model optimized for complex analysis, extended thinking, and agentic tasks.',
                intendedUse: 'Complex reasoning, code architecture, research synthesis, and autonomous agent workflows.',
                capabilities: [
                    'Extended chain-of-thought reasoning',
                    'Complex code generation and review',
                    'Multi-step agentic task execution',
                    'Nuanced analysis of ambiguous problems',
                ],
                limitations: [
                    'Higher latency due to extended reasoning',
                    'Knowledge cutoff limits real-time awareness',
                    'May over-elaborate on simple tasks',
                ],
                knownBiases: [
                    'Constitutional AI training may introduce safety-oriented verbosity',
                    'English-language primary training data',
                ],
                trainingDataSources: ['Publicly available text', 'RLHF and Constitutional AI'],
                ethicalConsiderations: [
                    'Designed with Constitutional AI alignment methodology',
                    'Not suitable as sole decision-maker for high-stakes outcomes',
                ],
            ),
        );
    }

    #[NoDiscard]
    public static function claudeSonnet(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'anthropic-claude-sonnet',
            name: 'Claude Sonnet 4',
            version: '2025-05-14',
            provider: 'Anthropic',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Balanced large language model optimized for performance and cost efficiency.',
                intendedUse: 'General-purpose text generation, code assistance, and conversational AI.',
                capabilities: [
                    'Natural language understanding and generation',
                    'Code generation across multiple languages',
                    'Document analysis and summarization',
                    'Structured data extraction',
                ],
                limitations: [
                    'Knowledge cutoff limits real-time awareness',
                    'May generate plausible but incorrect outputs',
                    'Reduced capability on highly specialized domains compared to Opus',
                ],
                knownBiases: [
                    'Constitutional AI training may introduce safety-oriented verbosity',
                    'English-language primary training data',
                ],
                trainingDataSources: ['Publicly available text', 'RLHF and Constitutional AI'],
                ethicalConsiderations: [
                    'Designed with Constitutional AI alignment methodology',
                    'Output should be reviewed before use in regulated decisions',
                ],
            ),
        );
    }

    #[NoDiscard]
    public static function claudeHaiku(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'anthropic-claude-haiku',
            name: 'Claude Haiku 3.5',
            version: '2024-10-22',
            provider: 'Anthropic',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Minimal,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Fast, compact language model optimized for low-latency and high-throughput tasks.',
                intendedUse: 'Classification, extraction, simple Q&A, and high-volume processing.',
                capabilities: [
                    'Fast text classification and extraction',
                    'Simple code generation',
                    'High-throughput document processing',
                    'Low-latency conversational responses',
                ],
                limitations: [
                    'Reduced reasoning depth compared to larger models',
                    'Limited complex multi-step reasoning',
                    'Shorter effective context utilization',
                ],
                knownBiases: [
                    'Compact model may amplify training data biases',
                    'English-language primary training data',
                ],
                trainingDataSources: ['Publicly available text', 'RLHF and Constitutional AI'],
                ethicalConsiderations: [
                    'Suitable for classification but not autonomous high-stakes decisions',
                    'Lower cost enables broader deployment: monitor for misuse',
                ],
            ),
        );
    }

    #[NoDiscard]
    public static function llama31(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'meta-llama-3.1',
            name: 'Llama 3.1 405B',
            version: '3.1-405b',
            provider: 'Meta',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Open-weight large language model for text generation and reasoning.',
                intendedUse: 'Self-hosted text generation, fine-tuning base, and research applications.',
                capabilities: [
                    'Natural language understanding and generation',
                    'Multilingual text processing',
                    'Code generation',
                    'On-premises deployment for data sovereignty',
                ],
                limitations: [
                    'Requires significant compute for 405B parameter model',
                    'No built-in content filtering without additional layers',
                    'May require fine-tuning for domain-specific tasks',
                ],
                knownBiases: [
                    'Training data reflects internet-scale biases',
                    'Performance varies across languages',
                ],
                trainingDataSources: ['Publicly available internet text'],
                ethicalConsiderations: [
                    'Open weights enable auditability but also unrestricted use',
                    'Deployers must implement their own safety guardrails',
                ],
            ),
        );
    }

    #[NoDiscard]
    public static function mistralLarge(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'mistral-large',
            name: 'Mistral Large',
            version: '2024-11',
            provider: 'Mistral AI',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Large language model with strong multilingual and reasoning capabilities.',
                intendedUse: 'Enterprise text generation, multilingual processing, and code assistance.',
                capabilities: [
                    'Strong multilingual understanding (EU languages)',
                    'Code generation and analysis',
                    'Function calling and structured output',
                    'European data residency options',
                ],
                limitations: [
                    'Smaller training corpus than largest competitors',
                    'Knowledge cutoff limits real-time awareness',
                    'Fewer third-party integrations than established providers',
                ],
                knownBiases: [
                    'European language emphasis in training data',
                    'May underperform on non-European low-resource languages',
                ],
                trainingDataSources: ['Publicly available text with European language emphasis'],
                ethicalConsiderations: [
                    'EU-based provider may simplify GDPR compliance',
                    'Output should be reviewed before use in regulated decisions',
                ],
            ),
        );
    }

    #[NoDiscard]
    public static function gemini20(?DateTimeImmutable $registeredAt = null): AiModel
    {
        return new AiModel(
            id: 'google-gemini-2.0',
            name: 'Gemini 2.0 Flash',
            version: '2.0-flash',
            provider: 'Google',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Production,
            registeredAt: $registeredAt ?? new DateTimeImmutable(),
            card: new ModelCard(
                description: 'Multimodal model with native tool use and agentic capabilities.',
                intendedUse: 'Multimodal reasoning, agentic workflows, and Google Cloud integration.',
                capabilities: [
                    'Native multimodal input (text, image, audio, video)',
                    'Built-in tool use and function calling',
                    'Large context window',
                    'Agentic task execution',
                ],
                limitations: [
                    'Tight coupling to Google Cloud ecosystem',
                    'Knowledge cutoff limits real-time awareness',
                    'May generate plausible but incorrect outputs',
                ],
                knownBiases: [
                    'Training data reflects Google Search index biases',
                    'May favor Google products in recommendations',
                ],
                trainingDataSources: ['Google web index', 'Publicly available datasets'],
                ethicalConsiderations: [
                    'Data processing subject to Google Cloud terms',
                    'Not suitable as sole decision-maker for consequential outcomes',
                ],
            ),
        );
    }
}
