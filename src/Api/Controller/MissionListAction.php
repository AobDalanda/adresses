<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Security\TrackingIdentityResolver;
use App\Service\MissionOverviewService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class MissionListAction
{
    private const STATUSES = ['all', 'en_cours', 'a_venir', 'terminee'];
    private const SORTS = ['default', 'scheduled_at', '-scheduled_at', 'completed_at', '-completed_at', 'started_at', '-started_at'];

    public function __construct(
        private TrackingIdentityResolver $identities,
        private MissionOverviewService $missions,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->identities->resolve($request);
        if ($identity === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }
        if (!$identity->isDriver() || $identity->userId === null) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        try {
            $filters = $this->filters($request);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 400);
        }

        try {
            $payload = $this->missions->listForDriver($identity->userId, $filters);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Unable to load missions'], 500);
        }

        $payload['links'] = $this->links($request, $filters['page'], $filters['perPage'], $payload['meta']['totalPages']);

        return new JsonResponse($payload);
    }

    /** @return array{status: string, page: int, perPage: int, sort: string, dateFrom: ?string, dateTo: ?string} */
    private function filters(Request $request): array
    {
        $status = $request->query->getString('status', 'all');
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status filter');
        }

        $missionType = $request->query->getString('mission_type', 'livraison');
        if ($missionType !== 'livraison') {
            throw new \InvalidArgumentException('Only mission_type=livraison is supported');
        }

        $page = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('per_page', 20);
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('page must be positive and per_page must be between 1 and 100');
        }

        $sort = $request->query->getString('sort', 'default');
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException('Invalid sort value');
        }

        $dateFrom = $this->date($request->query->get('date_from'), 'date_from');
        $dateTo = $this->date($request->query->get('date_to'), 'date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new \InvalidArgumentException('date_from must be before or equal to date_to');
        }

        return [
            'status' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => $sort,
            'dateFrom' => $dateFrom?->format(\DATE_ATOM),
            'dateTo' => $dateTo?->format(\DATE_ATOM),
        ];
    }

    private function date(mixed $value, string $name): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an ISO 8601 date', $name));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be an ISO 8601 date', $name));
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('%s must be an ISO 8601 date', $name));
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new \InvalidArgumentException(sprintf('%s must be an ISO 8601 date', $name));
        }

        return $date;
    }

    /** @return array{self: string, next: ?string, prev: ?string} */
    private function links(Request $request, int $page, int $perPage, int $totalPages): array
    {
        $query = $request->query->all();
        $query['per_page'] = $perPage;

        $url = static function (int $targetPage) use ($request, $query): string {
            $target = $query;
            $target['page'] = $targetPage;

            return $request->getPathInfo().'?'.http_build_query($target);
        };

        return [
            'self' => $url($page),
            'next' => $page < $totalPages ? $url($page + 1) : null,
            'prev' => $page > 1 ? $url($page - 1) : null,
        ];
    }
}
