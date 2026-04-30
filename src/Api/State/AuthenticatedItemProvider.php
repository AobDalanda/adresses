<?php

namespace App\Api\State;

use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AuthenticatedItemProvider implements ProviderInterface
{
    public function __construct(
        private ItemProvider $itemProvider,
        private JwtAuthService $jwt,
        private RequestStack $requestStack
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request || !$this->jwt->decodeFromRequest($request)) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return $this->itemProvider->provide($operation, $uriVariables, $context);
    }
}
