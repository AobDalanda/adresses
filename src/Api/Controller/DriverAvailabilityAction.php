<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Exception\DriverNotAuthorizedException;
use App\Exception\DriverProfileIncompleteException;
use App\Exception\DriverProfileNotFoundException;
use App\Security\TrackingIdentityResolver;
use App\Service\Tracking\DriverAvailabilityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DriverAvailabilityAction
{
    public function __construct(
        private TrackingIdentityResolver $identityResolver,
        private DriverAvailabilityService $availability,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $identity = $this->identityResolver->resolve($request);
        if ($identity === null || $identity->userId === null) {
            return new JsonResponse([
                'error' => 'UNAUTHORIZED',
                'message' => 'La session est invalide ou expirée.',
            ], 401);
        }
        if ($request->isMethod('GET')) {
            try {
                return new JsonResponse($this->availability->get($identity->userId));
            } catch (DriverProfileNotFoundException $exception) {
                return $this->error('DRIVER_PROFILE_NOT_FOUND', $exception->getMessage(), 404);
            } catch (DriverProfileIncompleteException $exception) {
                return $this->error('DRIVER_PROFILE_INCOMPLETE', $exception->getMessage(), 422);
            } catch (DriverNotAuthorizedException $exception) {
                return $this->error('PROVIDER_NOT_AUTHORIZED', $exception->getMessage(), 403);
            } catch (\Throwable $exception) {
                $this->logger->error('Unable to read driver availability', [
                    'driverId' => $identity->userId,
                    'exception' => $exception,
                ]);

                return $this->error('AVAILABILITY_READ_FAILED', 'Impossible de lire la disponibilité.', 500);
            }
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'INVALID_PAYLOAD',
                'message' => 'Le payload JSON est invalide.',
            ], 400);
        }

        if (!array_key_exists('online', $payload) || !is_bool($payload['online'])) {
            return new JsonResponse([
                'error' => 'INVALID_ONLINE_VALUE',
                'message' => 'Le champ online doit être un booléen.',
            ], 400);
        }
        try {
            // availabilityVersion is intentionally ignored during the mobile compatibility period.
            $state = $this->availability->set($identity->userId, $payload['online']);
        } catch (DriverProfileNotFoundException $exception) {
            return $this->error('DRIVER_PROFILE_NOT_FOUND', $exception->getMessage(), 404);
        } catch (DriverProfileIncompleteException $exception) {
            return $this->error('DRIVER_PROFILE_INCOMPLETE', $exception->getMessage(), 422);
        } catch (DriverNotAuthorizedException $exception) {
            return $this->error('PROVIDER_NOT_AUTHORIZED', $exception->getMessage(), 403);
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to update driver availability', [
                'driverId' => $identity->userId,
                'requestedOnline' => $payload['online'],
                'exception' => $exception,
            ]);

            return $this->error('AVAILABILITY_UPDATE_FAILED', 'Impossible de modifier la disponibilité.', 500);
        }

        return new JsonResponse($state);
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'message' => $message], $status);
    }
}
