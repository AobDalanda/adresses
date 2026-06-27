<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\JwtAuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class MobileJwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly JwtAuthService $jwt,
        private readonly AuthenticatedIdentityFactory $identities
    ) {
    }

    public function supports(Request $request): bool
    {
        $authorization = $request->headers->get('Authorization');

        return is_string($authorization) && str_starts_with($authorization, 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $claims = $this->jwt->decodeFromRequest($request);
        if (!is_array($claims) || !isset($claims['uid'])) {
            throw new BadCredentialsException('JWT invalide.');
        }

        $identity = match ($claims['typ'] ?? null) {
            'mobile' => $this->identities->fromMobileClaims($claims),
            'back_office' => $this->identities->fromBackOfficeClaims($claims),
            default => null,
        };
        if ($identity === null) {
            throw new BadCredentialsException('Identite introuvable.');
        }

        return new SelfValidatingPassport(
            new UserBadge($identity->getUserIdentifier(), static fn (): AuthenticatedIdentity => $identity)
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ): ?Response {
        // Existing v1 controllers retain their historical 401/403 payloads.
        return null;
    }
}
