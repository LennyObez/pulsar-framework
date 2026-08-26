<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Transparency;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function sprintf;
use function trim;

/**
 * The machine-readable mark Article 50(2) requires on generated output.
 *
 * WHAT THIS IS, STATED BEFORE ANYTHING ELSE. This marks content at the delivery
 * boundary. It is not a watermark: nothing here is embedded in the pixels of an
 * image or the samples of an audio signal, and nothing here survives a
 * re-encode, a screenshot or a copy-paste. Article 50(2) asks for marking that is
 * "effective, interoperable, robust and reliable AS FAR AS THIS IS TECHNICALLY
 * FEASIBLE", and the boundary is as far as a server-side web framework reaches. A
 * deployment generating images or audio discharges the robustness limb with a
 * provenance standard such as C2PA applied where the media is produced. Pulsar
 * does not do that, does not claim to, and the AiActMapping control for
 * Article 50(2) says so where an assessor will read it.
 *
 * What it does deliver is the machine-readable and detectable limb: an assertion,
 * carried with the response, that says this output was artificially generated,
 * which model produced it, and when. That is checkable by a machine, which is
 * what "machine-readable" and "detectable" mean, and it is the part a framework
 * can guarantee for every response rather than hope for.
 *
 * The model id is required. A mark that declines to say what generated the
 * content leaves an assessor with an assertion and no way to corroborate it, and
 * the model registry in this extension exists precisely so the id resolves to
 * something with a risk classification attached.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class SyntheticContentMark
{
    /**
     * @param non-empty-string $surfaceId the surface whose policy demanded this mark
     * @param non-empty-string $modelId   the registered model that produced the output
     * @param int              $generatedAt Unix timestamp of generation
     */
    public function __construct(
        public SyntheticContentKind $kind,
        public string $surfaceId,
        public string $modelId,
        public int $generatedAt,
    ) {
        if (trim($this->surfaceId) === '') {
            throw AiGovernanceException::surfaceIdRequired();
        }

        if (trim($this->modelId) === '') {
            throw AiGovernanceException::markRequiresModel($this->kind->value);
        }
    }

    /**
     * The mark as a structured value for a transport header.
     *
     * Shaped as an RFC 8941 structured-field dictionary so a proxy, a crawler or
     * an auditor's tooling can parse it without knowing anything about Pulsar.
     * The leading `ai-generated` member is the detection point: its presence is
     * the assertion, and everything after it is corroboration.
     */
    #[NoDiscard]
    public function toHeaderValue(): string
    {
        return sprintf(
            'ai-generated=?1, kind="%s", model="%s", surface="%s", generated=%d',
            $this->kind->value,
            $this->modelId,
            $this->surfaceId,
            $this->generatedAt,
        );
    }

    /**
     * The mark as data, for transports that carry structure rather than headers.
     *
     * @return array{ai_generated: true, kind: string, model: string, surface: string, generated_at: int}
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'ai_generated' => true,
            'kind' => $this->kind->value,
            'model' => $this->modelId,
            'surface' => $this->surfaceId,
            'generated_at' => $this->generatedAt,
        ];
    }
}
