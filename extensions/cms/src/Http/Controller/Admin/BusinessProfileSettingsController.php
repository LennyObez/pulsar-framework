<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Config\BusinessProfileConfig;
use Pulsar\Config\BusinessProfileProviderInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_array;
use function is_string;

/**
 * Admin controller for centralized business profile settings.
 *
 * Provides a single form where the administrator enters company information once.
 * Values are persisted via SettingsService (group: "business") and mapped to
 * BusinessProfileConfig for consumption by all extensions.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class BusinessProfileSettingsController extends AbstractAdminController
{
    /** @var list<string> */
    private const array PROFILE_FIELDS = [
        'company_name',
        'trading_name',
        'legal_form',
        'registration_number',
        'vat_number',
        'tax_id',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'region',
        'country',
        'phone',
        'email',
        'website',
        'iban',
        'bic',
        'bank_name',
        'logo_path',
        'peppol_id',
        'peppol_scheme',
    ];

    public function __construct(
        private SettingsServiceInterface $settingsService,
        private BusinessProfileProviderInterface $businessProfileProvider,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * Display the business profile edit form.
     */
    public function edit(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.view');

        $profile = $this->businessProfileProvider->getProfile();

        // Also load any overrides stored in CMS settings
        $settingsOverrides = $this->settingsService->getGroup('business');

        $data = [
            'profile' => $profile,
            'settingsOverrides' => $settingsOverrides,
            'fields' => self::PROFILE_FIELDS,
        ];

        return $this->respondWithView($request, 'admin.settings.business-profile', $data);
    }

    /**
     * Persist the updated business profile.
     */
    public function update(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.manage');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var array<string, mixed> $profileData */
        $profileData = is_array($body['profile'] ?? null) ? $body['profile'] : [];
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : 'Business profile updated';

        $updatedCount = 0;

        foreach (self::PROFILE_FIELDS as $field) {
            if (!isset($profileData[$field])) {
                continue;
            }

            $value = $profileData[$field];

            if (!is_string($value)) {
                continue;
            }

            // Store empty strings as null to keep settings clean
            $storedValue = $value !== '' ? $value : null;
            $this->settingsService->set('business', $field, $storedValue, null, $reason);
            $updatedCount++;
        }

        return Response::json([
            'status' => 'updated',
            'fields_updated' => $updatedCount,
        ]);
    }
}
