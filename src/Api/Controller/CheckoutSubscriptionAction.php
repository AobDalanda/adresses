<?php

namespace App\Api\Controller;

use App\Dto\Subscription\CheckoutSubscriptionInput;
use App\Exception\InvalidSubscriptionPlanException;
use App\Service\Subscription\PaymentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CheckoutSubscriptionAction
{
    public function __construct(
        private readonly AuthenticatedUserResolver $users,
        private readonly PaymentManager $payments,
        private readonly ValidatorInterface $validator
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->users->requireMobileUser($request);
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['message' => 'Invalid JSON body'], 400);
        }

        $input = new CheckoutSubscriptionInput();
        $input->planCode = isset($payload['planCode']) && is_string($payload['planCode']) ? $payload['planCode'] : null;
        $input->paymentProvider = isset($payload['paymentProvider']) && is_string($payload['paymentProvider']) ? $payload['paymentProvider'] : null;
        $input->phoneNumber = isset($payload['phoneNumber']) && is_string($payload['phoneNumber']) ? $payload['phoneNumber'] : null;

        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            return new JsonResponse(['message' => (string) $violations], 400);
        }

        try {
            $result = $this->payments->checkout($user, $input->planCode ?? '', $input->paymentProvider ?? '', $input->phoneNumber);
        } catch (InvalidSubscriptionPlanException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PLAN',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => [
                    'code' => $e->getMessage(),
                    'message' => 'Provider de paiement invalide.',
                ],
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'checkout' => $result,
        ], 201);
    }
}
