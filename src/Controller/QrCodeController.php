<?php

namespace App\Controller;

use App\Repository\QrCodeRepository;
use App\Service\JwtAuthService;
use App\Service\QrCodeAccessPolicy;
use App\Service\QrCodeBruteForceGuard;
use App\Service\QrScanLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class QrCodeController extends AbstractController
{
    public function __construct(
        private QrCodeRepository $qrCodes,
        private JwtAuthService $jwt,
        private QrCodeAccessPolicy $accessPolicy,
        private QrCodeBruteForceGuard $bruteForceGuard,
        private QrScanLogger $scanLogger,
        private LoggerInterface $logger,
        private RateLimiterFactory $qrRateLimiter
    ) {
    }

    public function show(string $token, Request $request): JsonResponse
    {
        $clientIp = $request->getClientIp() ?? '';
        $logContext = $this->buildLogContext($request, $token);

        try {
            if ($this->bruteForceGuard->isBlocked($clientIp, 'scan')) {
                $this->logScan($logContext, 'brute_force_detected', 'Trop de tentatives invalides');

                return $this->errorResponse('Trop de tentatives invalides. Réessayez plus tard.', 429);
            }

            if (!$this->isTokenFormatValid($token)) {
                $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'scan');
                $this->logScan($logContext, 'invalid', 'Token QR invalide');

                return $this->errorResponse('QR Code introuvable', 404);
            }

            $limit = $this->qrRateLimiter->create(sha1($clientIp));
            $rateLimit = $limit->consume(1);
            if (!$rateLimit->isAccepted()) {
                $retryAt = $rateLimit->getRetryAfter();
                $this->logScan($logContext, 'rate_limited', 'Trop de requêtes');

                throw new TooManyRequestsHttpException(
                    $retryAt !== null ? max(1, $retryAt->getTimestamp() - time()) : null,
                    'Trop de requêtes. Réessayez plus tard.'
                );
            }

            $auth = $this->jwt->decodeFromRequest($request);
            $authenticatedUserId = $this->accessPolicy->resolveAuthenticatedUserId($auth);
            $logContext['user_id'] = $authenticatedUserId;

            $address = $this->qrCodes->findByToken($token, $authenticatedUserId);
            if ($address === null) {
                $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'scan');
                $this->logScan($logContext, 'invalid', 'QR Code introuvable');

                return $this->errorResponse('QR Code introuvable', 404);
            }

            if (!$this->accessPolicy->isIpAllowed($clientIp)) {
                $this->logScan($logContext, 'blocked', 'Adresse IP non autorisée');

                return $this->errorResponse('Accès refusé', 403);
            }

            if (!(bool) $address['is_active']) {
                $this->logScan($logContext, 'blocked', 'QR Code désactivé');

                return $this->errorResponse('QR Code désactivé', 403);
            }

            if (($address['revoked_at'] ?? null) !== null) {
                $this->logScan($logContext, 'blocked', 'QR Code révoqué');

                return $this->errorResponse('QR Code désactivé', 403);
            }

            if ($this->isExpired($address['expires_at'] ?? null)) {
                $this->logScan($logContext, 'expired', 'QR Code expiré');

                return $this->errorResponse('QR Code expiré', 410);
            }

            if (!$this->accessPolicy->canAccessAddress($address, $authenticatedUserId)) {
                $this->logScan($logContext, 'blocked', 'Restrictions d’accès non satisfaites');

                return $this->errorResponse('Accès refusé', 403);
            }

            if (!$this->qrCodes->incrementCurrentScans((int) $address['qr_id'])) {
                $this->logScan($logContext, 'blocked', 'Limite de scans atteinte');

                return $this->errorResponse('Limite de scans atteinte', 403);
            }

            $this->bruteForceGuard->clear($clientIp, 'scan');
            $this->logScan($logContext, 'success', null);

            return $this->json([
                'success' => true,
                'address' => [
                    'name' => (string) $address['name'],
                    'description' => $address['description'] !== null ? (string) $address['description'] : null,
                    'latitude' => $address['latitude'] !== null ? (float) $address['latitude'] : null,
                    'longitude' => $address['longitude'] !== null ? (float) $address['longitude'] : null,
                ],
            ]);
        } catch (TooManyRequestsHttpException $e) {
            return $this->errorResponse($e->getMessage(), 429);
        } catch (\Throwable $e) {
            $this->logger->error('QR scan failed unexpectedly', [
                'token' => $token,
                'ip' => $clientIp,
                'exception' => $e,
            ]);
            $this->logScan($logContext, 'blocked', 'Erreur interne');

            return $this->errorResponse('Erreur interne lors du scan du QR Code', 500);
        }
    }

    private function isTokenFormatValid(string $token): bool
    {
        return preg_match('/^ADR_[A-Z0-9]{24,64}$/', $token) === 1;
    }

    private function isExpired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        $date = $expiresAt instanceof \DateTimeInterface ? $expiresAt : new \DateTimeImmutable((string) $expiresAt);

        return $date <= new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLogContext(Request $request, ?string $token): array
    {
        $userAgent = $request->headers->get('User-Agent');

        return [
            'token' => $token,
            'ip' => $request->getClientIp(),
            'user_agent' => $userAgent,
            'user_id' => null,
            'device' => $this->detectDevice($userAgent),
            'country' => $request->headers->get('CF-IPCountry')
                ?? $request->headers->get('X-Country-Code')
                ?? $request->headers->get('X-Country'),
            'city' => $request->headers->get('CF-IPCity')
                ?? $request->headers->get('X-City'),
            'latitude' => $this->readNullableFloatHeader($request, 'X-Latitude')
                ?? $this->readNullableFloatHeader($request, 'CF-IPLatitude'),
            'longitude' => $this->readNullableFloatHeader($request, 'X-Longitude')
                ?? $this->readNullableFloatHeader($request, 'CF-IPLongitude'),
        ];
    }

    private function detectDevice(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $ua = strtolower($userAgent);
        if (str_contains($ua, 'android')) {
            return 'android';
        }
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            return 'ios';
        }
        if (str_contains($ua, 'windows')) {
            return 'windows';
        }
        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) {
            return 'macos';
        }
        if (str_contains($ua, 'linux')) {
            return 'linux';
        }

        return 'unknown';
    }

    private function readNullableFloatHeader(Request $request, string $name): ?float
    {
        $value = $request->headers->get($name);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logScan(array $context, string $status, ?string $errorMessage): void
    {
        try {
            $this->scanLogger->log([
                ...$context,
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to persist QR scan log', [
                'status' => $status,
                'token' => $context['token'] ?? null,
                'exception' => $e,
            ]);
        }

        if ($status !== 'success') {
            $this->logger->warning('QR scan denied', [
                'status' => $status,
                'token' => $context['token'] ?? null,
                'ip' => $context['ip'] ?? null,
                'message' => $errorMessage,
            ]);
        }
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
