<?php

namespace App\Controller;

use App\Entity\SubscriptionPlan;
use App\Enum\PaymentProvider;
use App\Enum\UserSubscriptionStatus;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\UserSubscriptionRepository;
use App\Service\Subscription\SubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final class AdminSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly SubscriptionManager $subscriptions,
        private readonly UserSubscriptionRepository $userSubscriptions,
        private readonly SubscriptionPlanRepository $plans,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/subscriptions', methods: ['GET'])]
    public function listSubscriptions(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $items = array_map(static function ($subscription): array {
            return [
                'id' => $subscription->getId(),
                'userId' => $subscription->getUser()->getId(),
                'planCode' => $subscription->getPlan()->getCode()->value,
                'status' => $subscription->getStatus()->value,
                'expiresAt' => $subscription->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $this->userSubscriptions->findBy([], ['createdAt' => 'DESC'], 100));

        return new JsonResponse(['success' => true, 'subscriptions' => $items]);
    }

    #[Route('/users/{id}/subscription', methods: ['GET'])]
    public function userSubscription(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $user = $this->subscriptions->getUser($id);
        $subscription = $this->subscriptions->getActiveSubscription($user);

        return new JsonResponse([
            'success' => true,
            'subscription' => [
                'id' => $subscription->getId(),
                'planCode' => $subscription->getPlan()->getCode()->value,
                'status' => $subscription->getStatus()->value,
                'currentPeriodStart' => $subscription->getCurrentPeriodStart()->format(\DateTimeInterface::ATOM),
                'currentPeriodEnd' => $subscription->getCurrentPeriodEnd()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/users/{id}/subscription/activate', methods: ['POST'])]
    public function activateUserSubscription(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['planCode']) || !is_string($payload['planCode'])) {
            return new JsonResponse(['message' => 'planCode est requis'], 400);
        }

        $user = $this->subscriptions->getUser($id);
        $plan = $this->subscriptions->getPlanByCode($payload['planCode']);
        $subscription = $this->subscriptions->createPendingSubscription($user, $plan, PaymentProvider::MANUAL_ADMIN);
        $this->subscriptions->activateSubscription($subscription, 'manual_admin');
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'subscriptionId' => $subscription->getId(),
            'status' => $subscription->getStatus()->value,
        ]);
    }

    #[Route('/users/{id}/subscription/cancel', methods: ['POST'])]
    public function cancelUserSubscription(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $user = $this->subscriptions->getUser($id);
        $subscription = $this->subscriptions->cancelSubscription($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'subscriptionId' => $subscription->getId(),
            'status' => $subscription->getStatus()->value,
        ]);
    }

    #[Route('/subscription-plans', methods: ['POST'])]
    public function createPlan(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['code']) || !is_string($payload['code'])) {
            return new JsonResponse(['message' => 'Payload invalide'], 400);
        }

        $plan = $this->hydratePlan(new SubscriptionPlan(), $payload);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'id' => $plan->getId()], 201);
    }

    #[Route('/subscription-plans/{id}', methods: ['PATCH'])]
    public function updatePlan(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['message' => 'Forbidden'], 403);
        }

        $plan = $this->plans->find($id);
        if (!$plan instanceof SubscriptionPlan) {
            return new JsonResponse(['message' => 'Plan introuvable'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Payload invalide'], 400);
        }

        $this->hydratePlan($plan, $payload);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydratePlan(SubscriptionPlan $plan, array $payload): SubscriptionPlan
    {
        if (isset($payload['code']) && is_string($payload['code'])) {
            $plan->setCode(\App\Enum\SubscriptionPlanCode::from(strtoupper($payload['code'])));
        }
        if (isset($payload['name']) && is_string($payload['name'])) {
            $plan->setName($payload['name']);
        }
        if (array_key_exists('description', $payload) && ($payload['description'] === null || is_string($payload['description']))) {
            $plan->setDescription($payload['description']);
        }
        if (isset($payload['priceAmount']) && is_numeric($payload['priceAmount'])) {
            $plan->setPriceAmount((int) $payload['priceAmount']);
        }
        if (isset($payload['currency']) && is_string($payload['currency'])) {
            $plan->setCurrency(strtoupper($payload['currency']));
        }
        if (isset($payload['durationDays']) && is_numeric($payload['durationDays'])) {
            $plan->setDurationDays((int) $payload['durationDays']);
        }
        if (array_key_exists('isActive', $payload)) {
            $plan->setIsActive((bool) $payload['isActive']);
        }
        if (array_key_exists('maxAddresses', $payload)) {
            $plan->setMaxAddresses($payload['maxAddresses'] !== null ? (int) $payload['maxAddresses'] : null);
        }
        if (array_key_exists('maxQrCodes', $payload)) {
            $plan->setMaxQrCodes($payload['maxQrCodes'] !== null ? (int) $payload['maxQrCodes'] : null);
        }
        if (array_key_exists('maxDeliveriesPerMonth', $payload)) {
            $plan->setMaxDeliveriesPerMonth($payload['maxDeliveriesPerMonth'] !== null ? (int) $payload['maxDeliveriesPerMonth'] : null);
        }
        if (array_key_exists('canTrackDelivery', $payload)) {
            $plan->setCanTrackDelivery((bool) $payload['canTrackDelivery']);
        }
        if (array_key_exists('canUseCustomQrCode', $payload)) {
            $plan->setCanUseCustomQrCode((bool) $payload['canUseCustomQrCode']);
        }
        if (array_key_exists('canCreateBusinessAddress', $payload)) {
            $plan->setCanCreateBusinessAddress((bool) $payload['canCreateBusinessAddress']);
        }

        return $plan;
    }

    private function isAdmin(Request $request): bool
    {
        return $this->security->isGranted('ROLE_ADMIN');
    }
}
