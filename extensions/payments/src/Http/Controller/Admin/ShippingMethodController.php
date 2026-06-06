<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Shipping\ShippingMethodRate;
use Pulsar\Extension\Payments\Shipping\ShippingRateType;
use Pulsar\Extension\Payments\Shipping\ShippingZone;
use Pulsar\Extension\Payments\Shipping\ShippingZoneRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function bin2hex;
use function count;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function random_bytes;
use function str_contains;
use function strtoupper;

use const ENT_QUOTES;

/**
 * Admin CRUD controller for shipping zones and their methods.
 *
 * Provides a PrestaShop-style shipping management interface where
 * administrators configure zones, assign countries, and define
 * rate-based shipping methods per zone.
 *
 * Returns HTML for browser requests and JSON for API clients.
 */
#[Internal(reason: 'Admin HTTP controller; implementation detail')]
final readonly class ShippingMethodController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ShippingZoneRepositoryInterface $repository,
    ) {}

    /**
     * GET /admin/shipping
     *
     * List all shipping zones and their methods.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $zones = $this->repository->listAll();

        if ($this->wantsJson($request)) {
            return Response::json([
                'zones' => array_map($this->zoneToArray(...), $zones),
            ]);
        }

        return $this->renderListHtml($zones);
    }

    /**
     * GET /admin/shipping/create
     *
     * Display the shipping zone creation form.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function create(ServerRequestInterface $request): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json([
                'form' => 'create',
                'rate_types' => array_map(
                    static fn(ShippingRateType $t): string => $t->value,
                    ShippingRateType::cases(),
                ),
            ]);
        }

        return $this->renderFormHtml(null);
    }

    /**
     * POST /admin/shipping
     *
     * Save a new shipping zone.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function store(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $validation = $this->validateZoneInput($body);

        if ($validation !== null) {
            return Response::json(['error' => $validation], 422);
        }

        $zone = $this->buildZoneFromInput(bin2hex(random_bytes(16)), $body);
        $this->repository->save($zone);

        if ($this->wantsJson($request)) {
            return Response::json([
                'message' => 'payments.shipping.zone_created',
                'zone' => $this->zoneToArray($zone),
            ], 201);
        }

        return Response::redirect('/admin/shipping');
    }

    /**
     * GET /admin/shipping/{id}/edit
     *
     * Display the shipping zone edit form.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function edit(ServerRequestInterface $request): Response
    {
        $id = $this->resolveRouteParam($request, 'id');

        if ($id === '') {
            return Response::json(['error' => 'payments.shipping.id_required'], 422);
        }

        $zone = $this->repository->findById($id);

        if ($zone === null) {
            return Response::json(['error' => 'payments.shipping.zone_not_found'], 404);
        }

        if ($this->wantsJson($request)) {
            return Response::json([
                'form' => 'edit',
                'zone' => $this->zoneToArray($zone),
            ]);
        }

        return $this->renderFormHtml($zone);
    }

    /**
     * PUT /admin/shipping/{id}
     *
     * Update an existing shipping zone.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function update(ServerRequestInterface $request): Response
    {
        $id = $this->resolveRouteParam($request, 'id');

        if ($id === '') {
            return Response::json(['error' => 'payments.shipping.id_required'], 422);
        }

        $existing = $this->repository->findById($id);

        if ($existing === null) {
            return Response::json(['error' => 'payments.shipping.zone_not_found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $validation = $this->validateZoneInput($body);

        if ($validation !== null) {
            return Response::json(['error' => $validation], 422);
        }

        $zone = $this->buildZoneFromInput($id, $body);
        $this->repository->save($zone);

        if ($this->wantsJson($request)) {
            return Response::json([
                'message' => 'payments.shipping.zone_updated',
                'zone' => $this->zoneToArray($zone),
            ]);
        }

        return Response::redirect('/admin/shipping');
    }

    /**
     * DELETE /admin/shipping/{id}
     *
     * Delete a shipping zone.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function destroy(ServerRequestInterface $request): Response
    {
        $id = $this->resolveRouteParam($request, 'id');

        if ($id === '') {
            return Response::json(['error' => 'payments.shipping.id_required'], 422);
        }

        $deleted = $this->repository->delete($id);

        if (!$deleted) {
            return Response::json(['error' => 'payments.shipping.zone_not_found'], 404);
        }

        if ($this->wantsJson($request)) {
            return Response::json(['message' => 'payments.shipping.zone_deleted']);
        }

        return Response::redirect('/admin/shipping');
    }

    /**
     * Validate zone creation/update input.
     *
     * @param array<string, mixed> $body
     * @return string|null Error message or null if valid
     */
    private function validateZoneInput(array $body): ?string
    {
        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';

        if ($name === '') {
            return 'payments.shipping.name_required';
        }

        /** @var mixed $countries */
        $countries = $body['countries'] ?? null;

        if (!is_array($countries) || $countries === []) {
            return 'payments.shipping.countries_required';
        }

        return null;
    }

    /**
     * Build a ShippingZone from validated request input.
     *
     * @param non-empty-string $id
     * @param array<string, mixed> $body
     */
    private function buildZoneFromInput(string $id, array $body): ShippingZone
    {
        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) && $rawName !== '' ? $rawName : 'Unnamed Zone';

        /** @var list<string> $countries */
        $countries = [];
        /** @var mixed $rawCountriesRaw */
        $rawCountriesRaw = $body['countries'] ?? null;
        $rawCountries = is_array($rawCountriesRaw) ? $rawCountriesRaw : [];

        /** @var mixed $code */
        foreach ($rawCountries as $code) {
            if (is_string($code) && $code !== '') {
                $countries[] = strtoupper($code);
            }
        }

        $methods = [];
        /** @var mixed $rawMethodsRaw */
        $rawMethodsRaw = $body['methods'] ?? null;
        $rawMethods = is_array($rawMethodsRaw) ? $rawMethodsRaw : [];

        /** @var mixed $rawMethod */
        foreach ($rawMethods as $rawMethod) {
            if (!is_array($rawMethod)) {
                continue;
            }

            /** @var array<string, mixed> $rawMethod */
            $method = $this->buildMethodFromInput($rawMethod);

            if ($method !== null) {
                $methods[] = $method;
            }
        }

        return new ShippingZone(
            id: $id,
            name: $name,
            countries: $countries,
            methods: $methods,
        );
    }

    /**
     * Build a ShippingMethodRate from a single method input.
     *
     * @param array<string, mixed> $data
     */
    private function buildMethodFromInput(array $data): ?ShippingMethodRate
    {
        /** @var mixed $rawMethodId */
        $rawMethodId = $data['method_id'] ?? null;
        $methodId = is_string($rawMethodId) ? $rawMethodId : '';

        if ($methodId === '') {
            return null;
        }

        /** @var mixed $rawLabel */
        $rawLabel = $data['label'] ?? null;
        $label = is_string($rawLabel) && $rawLabel !== '' ? $rawLabel : $methodId;
        /** @var mixed $rawType */
        $rawType = $data['type'] ?? null;
        $typeValue = is_string($rawType) ? $rawType : 'flat';
        $type = ShippingRateType::tryFrom($typeValue) ?? ShippingRateType::Flat;

        /** @var mixed $rawBaseRate */
        $rawBaseRate = $data['base_rate'] ?? null;
        $baseRateAmount = (is_string($rawBaseRate) || is_int($rawBaseRate) || is_float($rawBaseRate))
            && is_numeric($rawBaseRate)
            ? (int) $rawBaseRate
            : 0;
        /** @var mixed $rawCurrency */
        $rawCurrency = $data['currency'] ?? null;
        $currencyCode = is_string($rawCurrency) ? $rawCurrency : 'EUR';
        $currency = Currency::tryFrom($currencyCode) ?? Currency::EUR;

        $baseRate = Money::of($baseRateAmount, $currency);

        /** @var mixed $rawId */
        $rawId = $data['id'] ?? null;

        $toNullableInt = static function (mixed $value): ?int {
            return (is_string($value) || is_int($value) || is_float($value)) && is_numeric($value)
                ? (int) $value
                : null;
        };

        return new ShippingMethodRate(
            id: is_string($rawId) && $rawId !== '' ? $rawId : bin2hex(random_bytes(16)),
            methodId: $methodId,
            label: $label,
            type: $type,
            baseRate: $baseRate,
            freeAboveAmount: $toNullableInt($data['free_above'] ?? null),
            perItemAmount: $toNullableInt($data['per_item'] ?? null),
            minWeightGrams: $toNullableInt($data['min_weight'] ?? null),
            maxWeightGrams: $toNullableInt($data['max_weight'] ?? null),
            estimatedDaysMin: $toNullableInt($data['estimated_days_min'] ?? null),
            estimatedDaysMax: $toNullableInt($data['estimated_days_max'] ?? null),
            enabled: (bool) ($data['enabled'] ?? true),
        );
    }

    /**
     * Serialize a zone to a JSON-safe array.
     *
     * @return array<string, mixed>
     */
    private function zoneToArray(ShippingZone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'countries' => $zone->countries,
            'methods' => array_map(static fn(ShippingMethodRate $m): array => [
                'id' => $m->id,
                'method_id' => $m->methodId,
                'label' => $m->label,
                'type' => $m->type->value,
                'base_rate' => $m->baseRate->amount,
                'currency' => $m->baseRate->currency->value,
                'free_above' => $m->freeAboveAmount,
                'per_item' => $m->perItemAmount,
                'min_weight' => $m->minWeightGrams,
                'max_weight' => $m->maxWeightGrams,
                'estimated_days_min' => $m->estimatedDaysMin,
                'estimated_days_max' => $m->estimatedDaysMax,
                'estimated_delivery' => $m->estimatedDelivery(),
                'enabled' => $m->enabled,
            ], $zone->methods),
        ];
    }

    /**
     * Render the zone list page as HTML.
     *
     * @param list<ShippingZone> $zones
     */
    private function renderListHtml(array $zones): Response
    {
        $rowsHtml = '';

        foreach ($zones as $zone) {
            $safeName = htmlspecialchars($zone->name, ENT_QUOTES, 'UTF-8');
            $safeId = htmlspecialchars($zone->id, ENT_QUOTES, 'UTF-8');
            $countriesStr = htmlspecialchars(implode(', ', $zone->countries), ENT_QUOTES, 'UTF-8');
            $methodCount = count($zone->methods);
            $enabledCount = count($zone->enabledMethods());

            $rowsHtml .= <<<HTML
                    <tr>
                        <td>{$safeName}</td>
                        <td>{$countriesStr}</td>
                        <td>{$enabledCount} / {$methodCount}</td>
                        <td>
                            <a href="/admin/shipping/{$safeId}/edit" class="pui-btn pui-btn--sm">Edit</a>
                            <form method="post" action="/admin/shipping/{$safeId}" class="pui-inline">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="pui-btn pui-btn--sm pui-btn--danger"
                                        onclick="return confirm('Delete this shipping zone?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                HTML;
        }

        $emptyMessage = $zones === []
            ? '<tr><td colspan="4" class="pui-text-center pui-text-muted">No shipping zones configured.</td></tr>'
            : '';

        return Response::html(
            <<<HTML
                <!DOCTYPE html>
                <html lang="en" data-theme="dark">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>Shipping Zones</title>
                    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
                </head>
                <body>
                    <nav class="pui-navbar">
                        <a href="/admin" class="pui-navbar__brand">Admin</a>
                        <div class="pui-navbar__links">
                            <a href="/admin/payments">Payments</a>
                            <a href="/admin/shipping" class="active">Shipping</a>
                        </div>
                    </nav>
                    <main class="pui-container">
                        <div class="pui-flex pui-justify-between pui-items-center pui-mb-lg">
                            <h1>Shipping Zones</h1>
                            <a href="/admin/shipping/create" class="pui-btn pui-btn--primary">Create Zone</a>
                        </div>
                        <table class="pui-table">
                            <thead>
                                <tr>
                                    <th>Zone Name</th>
                                    <th>Countries</th>
                                    <th>Methods</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$rowsHtml}
                                {$emptyMessage}
                            </tbody>
                        </table>
                    </main>
                </body>
                </html>
                HTML,
        );
    }

    /**
     * Render the zone create/edit form as HTML.
     */
    private function renderFormHtml(?ShippingZone $zone): Response
    {
        $isEdit = $zone !== null;
        $title = $isEdit ? 'Edit Shipping Zone' : 'Create Shipping Zone';
        $action = $isEdit ? '/admin/shipping/' . htmlspecialchars($zone->id, ENT_QUOTES, 'UTF-8') : '/admin/shipping';
        $methodHidden = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $nameValue = $isEdit ? htmlspecialchars($zone->name, ENT_QUOTES, 'UTF-8') : '';
        $countriesValue = $isEdit ? htmlspecialchars(implode(', ', $zone->countries), ENT_QUOTES, 'UTF-8') : '';

        $methodsHtml = '';

        if ($isEdit) {
            foreach ($zone->methods as $method) {
                $mLabel = htmlspecialchars($method->label, ENT_QUOTES, 'UTF-8');
                $mId = htmlspecialchars($method->methodId, ENT_QUOTES, 'UTF-8');
                $mType = htmlspecialchars($method->type->value, ENT_QUOTES, 'UTF-8');
                $mRate = $method->baseRate->format();
                $mSymbol = $method->baseRate->currency->symbol();
                $mEnabled = $method->enabled ? 'Yes' : 'No';

                $methodsHtml .= <<<HTML
                        <tr>
                            <td>{$mId}</td>
                            <td>{$mLabel}</td>
                            <td>{$mType}</td>
                            <td>{$mSymbol}{$mRate}</td>
                            <td>{$mEnabled}</td>
                        </tr>
                    HTML;
            }
        }

        return Response::html(
            <<<HTML
                <!DOCTYPE html>
                <html lang="en" data-theme="dark">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>{$title}</title>
                    <link rel="stylesheet" href="/ui/css/pulsar-ui.css">
                </head>
                <body>
                    <nav class="pui-navbar">
                        <a href="/admin" class="pui-navbar__brand">Admin</a>
                        <div class="pui-navbar__links">
                            <a href="/admin/payments">Payments</a>
                            <a href="/admin/shipping" class="active">Shipping</a>
                        </div>
                    </nav>
                    <main class="pui-container">
                        <h1>{$title}</h1>
                        <form method="post" action="{$action}">
                            {$methodHidden}
                            <div class="pui-form-group">
                                <label for="name" class="pui-label">Zone Name</label>
                                <input type="text" id="name" name="name" value="{$nameValue}"
                                       class="pui-input" required placeholder="e.g., Europe, North America">
                            </div>
                            <div class="pui-form-group">
                                <label for="countries" class="pui-label">Countries (comma-separated ISO codes)</label>
                                <input type="text" id="countries" name="countries_csv" value="{$countriesValue}"
                                       class="pui-input" required placeholder="e.g., BE, NL, DE, FR">
                            </div>
                            <div class="pui-flex pui-gap-md pui-mt-lg">
                                <button type="submit" class="pui-btn pui-btn--primary">Save Zone</button>
                                <a href="/admin/shipping" class="pui-btn pui-btn--outline">Cancel</a>
                            </div>
                        </form>
                        {$methodsHtml}
                    </main>
                </body>
                </html>
                HTML,
        );
    }

    private function resolveRouteParam(ServerRequestInterface $request, string $param): string
    {
        /** @var mixed $value */
        $value = $request->getAttribute($param);

        return is_string($value) ? $value : '';
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return $accept === 'application/json' || str_contains($accept, 'application/json');
    }
}
