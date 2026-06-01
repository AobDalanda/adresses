<?php

namespace App\Api\Controller;

use App\Dto\Subscription\ChangePlanInput;
use App\Service\Subscription\PaymentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ChangeSubscriptionPlanAction
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

        $input = new ChangePlanInput();
        $input->planCode = isset($payload['planCode']) && is_string($payload['planCode']) ? $payload['planCode'] : null;
        $input->paymentProvider = isset($payload['paymentProvider']) && is_string($payload['paymentProvider']) ? $payload['paymentProvider'] : null;

        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            return new JsonResponse(['message' => (string) $violations], 400);
        }

        $result = $this->payments->checkout($user, $input->planCode ?? '', $input->paymentProvider ?? '', null);

        return new JsonResponse([
            'success' => true,
            'checkout' => $result,
        ], 201);
    }
}
