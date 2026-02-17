<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Risk;

use Pulsar\Api\Api;

/**
 * ICT asset record for the information asset register per DORA Article 8.
 *
 * Financial entities must maintain an up-to-date register of all ICT assets,
 * including information about their criticality, dependencies, and data flows.
 */
#[Api(since: '1.0.0')]
final readonly class IctAsset
{
    /**
     * @param list<string> $dependencies  IDs of assets this asset depends on
     * @param list<string> $dataFlows     Descriptions of data flows involving this asset
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $owner,
        public IctAssetCriticality $criticality,
        public array $dependencies = [],
        public array $dataFlows = [],
        public ?string $thirdPartyProvider = null,
        public ?string $location = null,
        public ?string $recoveryTimeObjective = null,
        public ?string $recoveryPointObjective = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'owner' => $this->owner,
            'criticality' => $this->criticality->value,
        ];

        if ($this->dependencies !== []) {
            $data['dependencies'] = $this->dependencies;
        }

        if ($this->dataFlows !== []) {
            $data['data_flows'] = $this->dataFlows;
        }

        if ($this->thirdPartyProvider !== null) {
            $data['third_party_provider'] = $this->thirdPartyProvider;
        }

        if ($this->location !== null) {
            $data['location'] = $this->location;
        }

        if ($this->recoveryTimeObjective !== null) {
            $data['recovery_time_objective'] = $this->recoveryTimeObjective;
        }

        if ($this->recoveryPointObjective !== null) {
            $data['recovery_point_objective'] = $this->recoveryPointObjective;
        }

        return $data;
    }
}
