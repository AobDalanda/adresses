<?php

namespace App\Api\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class AuthenticatedCollectionProvider implements ProviderInterface
{
    public function __construct(
        private CollectionProvider $collectionProvider,
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

        return $this->collectionProvider->provide($operation, $uriVariables, $context);
    }
}
