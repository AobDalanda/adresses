<?php

namespace App\Api\Controller;

use App\Enum\SubscriptionPlanCode;
use App\Exception\NoActiveSubscriptionException;
use App\Service\JwtAuthService;
use App\Service\Subscription\SubscriptionManager;
use App\Service\UserAddressService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserAddressDeleteAction
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly SubscriptionManager $subscriptions,
        private readonly UserAddressService $userAddresses,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, ?int $addressId = null, ?int $id = null): JsonResponse
    {
        $auth = $this->jwt->decodeFromRequest($request);
        if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
            $this->logger->warning(sprintf(
                'Address delete denied: unauthorized request route_address_id=%s route_id=%s ip=%s',
                $addressId !== null ? (string) $addressId : 'null',
                $id !== null ? (string) $id : 'null',
                $request->getClientIp() ?? ''
            ), [
                'route_address_id' => $addressId,
                'route_id' => $id,
                'ip' => $request->getClientIp(),
            ]);

            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $addressId ??= $id;
        if ($addressId <= 0) {
            $this->logger->warning(sprintf(
                'Address delete denied: invalid address id uid=%d sub=%s address_id=%s route_id=%s',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId !== null ? (string) $addressId : 'null',
                $id !== null ? (string) $id : 'null'
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
                'route_id' => $id,
            ]);

            return new JsonResponse(['message' => 'addressId est invalide'], 400);
        }

        try {
            $user = $this->subscriptions->getUser((int) $auth['uid']);
            $subscription = $this->subscriptions->getActiveSubscription($user);
            $planCode = $subscription->getPlan()->getCode();
        } catch (NoActiveSubscriptionException) {
            $this->logger->warning(sprintf(
                'Address delete denied: active subscription required uid=%d sub=%s address_id=%d',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => 'ACTIVE_SUBSCRIPTION_REQUIRED',
                    'message' => 'Un abonnement actif est requis pour supprimer une adresse.',
                ],
            ], 403);
        } catch (\Throwable) {
            $this->logger->error(sprintf(
                'Address delete failed: subscription lookup error uid=%d sub=%s address_id=%d',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
            ]);

            return new JsonResponse(['message' => 'Erreur lors de la vérification de l’abonnement'], 500);
        }

        if (!in_array($planCode, [SubscriptionPlanCode::PREMIUM, SubscriptionPlanCode::BUSINESS], true)) {
            $this->logger->warning(sprintf(
                'Address delete denied: unsupported subscription plan uid=%d sub=%s address_id=%d plan_code=%s',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId,
                $planCode->value
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
                'plan_code' => $planCode->value,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => 'SUBSCRIPTION_PLAN_REQUIRED',
                    'message' => 'La suppression d’adresse est réservée aux abonnements Premium et Business.',
                    'requiredPlans' => [
                        SubscriptionPlanCode::PREMIUM->value,
                        SubscriptionPlanCode::BUSINESS->value,
                    ],
                ],
            ], 403);
        }

        $isDefault = $this->userAddresses->isDefaultAddress((int) $auth['uid'], $addressId);
        if ($isDefault === null) {
            $this->logger->warning(sprintf(
                'Address delete denied: address not linked to user uid=%d sub=%s address_id=%d',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
            ]);

            return new JsonResponse(['message' => 'Adresse non liée à cet utilisateur'], 404);
        }

        if ($isDefault) {
            $this->logger->warning(sprintf(
                'Address delete denied: default address uid=%d sub=%s address_id=%d',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => 'DEFAULT_ADDRESS_DELETE_FORBIDDEN',
                    'message' => 'L’adresse par défaut ne peut pas être supprimée.',
                ],
            ], 409);
        }

        try {
            $deleted = $this->userAddresses->deleteUserAddress((int) $auth['uid'], $addressId);
        } catch (\Throwable) {
            $this->logger->error(sprintf(
                'Address delete failed unexpectedly uid=%d sub=%s address_id=%d',
                (int) $auth['uid'],
                (string) ($auth['sub'] ?? ''),
                $addressId
            ), [
                'uid' => (int) $auth['uid'],
                'sub' => (string) ($auth['sub'] ?? ''),
                'address_id' => $addressId,
            ]);

            return new JsonResponse(['message' => 'Erreur lors de la suppression de l’adresse utilisateur'], 500);
        }

        $this->logger->info(sprintf(
            'Address deleted successfully uid=%d sub=%s address_id=%d deleted=%s',
            (int) $auth['uid'],
            (string) ($auth['sub'] ?? ''),
            $addressId,
            $deleted ? 'true' : 'false'
        ), [
            'uid' => (int) $auth['uid'],
            'sub' => (string) ($auth['sub'] ?? ''),
            'address_id' => $addressId,
            'deleted' => $deleted,
        ]);

        return new JsonResponse([
            'success' => true,
            'addressId' => $addressId,
            'deleted' => true,
        ]);
    }
}
