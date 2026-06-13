<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\ProviderApiRolloutPolicy;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ProviderApiDeprecationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly ProviderApiRolloutPolicy $rollout,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $version = $this->providerApiVersion($path);
        if ($version === null) {
            return;
        }

        $response = $event->getResponse();
        if ($version === 'v1') {
            $response->headers->set('Deprecation', 'true');
            $response->headers->set(
                'Link',
                '</api/v2/provider/applications>; rel="successor-version"',
                false,
            );
            $sunset = $this->rollout->sunsetHttpDate();
            if ($sunset !== null) {
                $response->headers->set('Sunset', $sunset);
            }
        }

        try {
            $this->recordUsage(
                $version,
                (string) ($request->attributes->get('_route') ?? $path),
                $request->headers->get('X-App-Version', 'unknown'),
                intdiv($response->getStatusCode(), 100),
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Provider API usage metric could not be recorded.', [
                'version' => $version,
                'path' => $path,
                'exception' => $exception,
            ]);
        }
    }

    private function providerApiVersion(string $path): ?string
    {
        if (str_starts_with($path, '/api/v2/provider/')) {
            return 'v2';
        }

        $v1Paths = [
            '/api/v1/user/register/driver',
            '/api/v1/provider/',
            '/api/v1/admin/providers',
            '/api/v1/uploads',
        ];
        foreach ($v1Paths as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return 'v1';
            }
        }

        return null;
    }

    private function recordUsage(
        string $version,
        string $routeName,
        string $clientVersion,
        int $responseClass,
    ): void {
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO provider_api_usage_daily (
                    usage_date, api_version, route_name, client_version,
                    response_class, request_count
                )
                VALUES (CURRENT_DATE, :version, :routeName, :clientVersion, :responseClass, 1)
                ON CONFLICT (
                    usage_date, api_version, route_name, client_version, response_class
                ) DO UPDATE SET request_count = provider_api_usage_daily.request_count + 1
                SQL,
            [
                'version' => $version,
                'routeName' => mb_substr($routeName, 0, 190),
                'clientVersion' => mb_substr(trim($clientVersion) ?: 'unknown', 0, 80),
                'responseClass' => max(1, min(5, $responseClass)),
            ],
        );
    }
}
