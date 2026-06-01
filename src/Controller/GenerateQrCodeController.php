<?php

namespace App\Controller;

use App\Service\AddressQrCodeService;
use App\Service\JwtAuthService;
use App\Service\QrCodeBruteForceGuard;
use App\Service\Subscription\SubscriptionManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class GenerateQrCodeController extends AbstractController
{
    public function __construct(
        private JwtAuthService $jwt,
        private AddressQrCodeService $qrCodes,
        private QrCodeBruteForceGuard $bruteForceGuard,
        private SubscriptionManager $subscriptions,
        private LoggerInterface $logger,
        private RateLimiterFactory $qrGenerateRateLimiter
    ) {
    }

    public function generate(Request $request): JsonResponse
    {
        $clientIp = $request->getClientIp() ?? '';

        try {
            if ($this->bruteForceGuard->isBlocked($clientIp, 'generate')) {
                return $this->json([
                    'success' => false,
                    'message' => 'Trop de tentatives invalides. Réessayez plus tard.',
                ], 429);
            }

            $auth = $this->jwt->decodeFromRequest($request);
            if (!$auth || ($auth['typ'] ?? null) !== 'mobile' || !isset($auth['uid'])) {
                $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'generate');

                return $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $limiter = $this->qrGenerateRateLimiter->create(sprintf('%s:%s', (string) $auth['uid'], sha1($clientIp)));
            $limit = $limiter->consume(1);
            if (!$limit->isAccepted()) {
                $retryAt = $limit->getRetryAfter();

                throw new TooManyRequestsHttpException(
                    $retryAt !== null ? max(1, $retryAt->getTimestamp() - time()) : null,
                    'Trop de requêtes. Réessayez plus tard.'
                );
            }

            $payload = json_decode($request->getContent(), true);
            if (!is_array($payload)) {
                $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'generate');

                return $this->json([
                    'success' => false,
                    'message' => 'Invalid JSON body',
                ], 400);
            }

            $addressId = $payload['addressId'] ?? null;
            if (!is_int($addressId) && !(is_string($addressId) && ctype_digit($addressId))) {
                $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'generate');

                return $this->json([
                    'success' => false,
                    'message' => 'addressId est requis',
                ], 400);
            }

            $addressId = (int) $addressId;
            if ($addressId <= 0) {
                $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'generate');

                return $this->json([
                    'success' => false,
                    'message' => 'addressId invalide',
                ], 400);
            }

            $result = $this->qrCodes->generateForUser(
                (int) $auth['uid'],
                $addressId
            );
            $result['qr_url'] = $this->buildSecureQrUrl($request, $result['token']);

            $this->bruteForceGuard->clear($clientIp, 'generate');

            return $this->json([
                'success' => true,
                'data' => $result,
            ], 201);
        } catch (\DomainException $e) {
            $this->bruteForceGuard->registerInvalidAttempt($clientIp, 'generate');

            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (TooManyRequestsHttpException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 429);
        } catch (\Throwable $e) {
            $this->logger->error('QR generation failed unexpectedly', [
                'ip' => $clientIp,
                'exception' => $e,
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Erreur interne lors de la génération du QR Code',
            ], 500);
        }
    }

    private function buildSecureQrUrl(Request $request, string $token): string
    {
        $url = $this->generateUrl('app_qrcode_public', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($request->isSecure()) {
            return $url;
        }

        if (str_starts_with($url, 'http://localhost') || str_starts_with($url, 'http://127.0.0.1')) {
            return $url;
        }

        return preg_replace('/^http:/', 'https:', $url) ?? $url;
    }
}
