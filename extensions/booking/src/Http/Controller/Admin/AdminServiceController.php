<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Http\Controller\Admin;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Booking\Domain\Service;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;

use function bin2hex;
use function is_string;
use function random_bytes;

/**
 * Admin CRUD for bookable services and categories.
 */
#[Internal]
final readonly class AdminServiceController
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * GET /admin/booking/services: list services.
     */
    public function listServices(Request $request): Response
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_services ORDER BY name ASC',
        );

        $services = [];
        foreach ($result->rows as $row) {
            $services[] = [
                'id' => $row->getString('id'),
                'name' => $row->getString('name'),
                'description' => $row->getString('description'),
                'duration' => $row->getInt('duration'),
                'base_price' => Money::of($row->getInt('base_price'), Currency::from($row->getString('base_currency')))->format(),
                'deposit_percent' => $row->getInt('deposit_percent'),
                'active' => (bool) $row->getInt('active'),
            ];
        }

        return Response::json(['services' => $services]);
    }

    /**
     * POST /admin/booking/services: create a service.
     */
    public function createService(Request $request): Response
    {
        $id = bin2hex(random_bytes(16));
        $name = (string) ($request->post['name'] ?? '');
        $description = (string) ($request->post['description'] ?? '');
        $duration = (int) ($request->post['duration'] ?? 60);
        $basePrice = (int) ($request->post['base_price'] ?? 0);
        $currency = (string) ($request->post['currency'] ?? 'USD');
        $depositPercent = (int) ($request->post['deposit_percent'] ?? 20);
        /** @var mixed $categoryId */
        $categoryId = $request->post['category_id'] ?? null;
        $categoryIdStr = is_string($categoryId) ? $categoryId : null;

        if ($name === '') {
            return Response::json(['error' => 'Name is required'], 400);
        }

        $this->connection->execute(
            'INSERT INTO booking_services (id, name, description, duration, base_price, base_currency, deposit_percent, category_id, active) VALUES (:id, :name, :description, :duration, :base_price, :base_currency, :deposit_percent, :category_id, 1)',
            [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'duration' => $duration,
                'base_price' => $basePrice,
                'base_currency' => $currency,
                'deposit_percent' => $depositPercent,
                'category_id' => $categoryIdStr,
            ],
        );

        return Response::json(['id' => $id], 201);
    }

    /**
     * PUT /admin/booking/services/{id}: update a service.
     */
    public function updateService(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');
        $name = (string) ($request->post['name'] ?? '');
        $description = (string) ($request->post['description'] ?? '');
        $duration = (int) ($request->post['duration'] ?? 60);
        $basePrice = (int) ($request->post['base_price'] ?? 0);
        $currency = (string) ($request->post['currency'] ?? 'USD');
        $depositPercent = (int) ($request->post['deposit_percent'] ?? 20);
        $active = (bool) ($request->post['active'] ?? true);

        $this->connection->execute(
            'UPDATE booking_services SET name = :name, description = :description, duration = :duration, base_price = :base_price, base_currency = :base_currency, deposit_percent = :deposit_percent, active = :active WHERE id = :id',
            [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'duration' => $duration,
                'base_price' => $basePrice,
                'base_currency' => $currency,
                'deposit_percent' => $depositPercent,
                'active' => $active ? 1 : 0,
            ],
        );

        return Response::json(['id' => $id]);
    }

    /**
     * DELETE /admin/booking/services/{id}: delete a service.
     */
    public function deleteService(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');

        $this->connection->execute(
            'DELETE FROM booking_services WHERE id = :id',
            ['id' => $id],
        );

        return Response::noContent();
    }

    /**
     * GET /admin/booking/categories: list categories.
     */
    public function listCategories(Request $request): Response
    {
        $result = $this->connection->query(
            'SELECT * FROM booking_service_categories ORDER BY sort_order ASC',
        );

        $categories = [];
        foreach ($result->rows as $row) {
            $categories[] = [
                'id' => $row->getString('id'),
                'name' => $row->getString('name'),
                'slug' => $row->getString('slug'),
                'sort_order' => $row->getInt('sort_order'),
            ];
        }

        return Response::json(['categories' => $categories]);
    }

    /**
     * POST /admin/booking/categories: create a category.
     */
    public function createCategory(Request $request): Response
    {
        $id = bin2hex(random_bytes(16));
        $name = (string) ($request->post['name'] ?? '');
        $slug = (string) ($request->post['slug'] ?? '');
        $sortOrder = (int) ($request->post['sort_order'] ?? 0);

        if ($name === '' || $slug === '') {
            return Response::json(['error' => 'Name and slug are required'], 400);
        }

        $this->connection->execute(
            'INSERT INTO booking_service_categories (id, name, slug, sort_order) VALUES (:id, :name, :slug, :sort_order)',
            ['id' => $id, 'name' => $name, 'slug' => $slug, 'sort_order' => $sortOrder],
        );

        return Response::json(['id' => $id], 201);
    }
}
