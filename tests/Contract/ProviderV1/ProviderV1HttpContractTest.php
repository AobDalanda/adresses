<?php

declare(strict_types=1);

namespace App\Tests\Contract\ProviderV1;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ProviderV1HttpContractTest extends WebTestCase
{
    public function testProviderProfileGetRequiresMobileAuthentication(): void
    {
        $response = $this->request('GET', '/api/v1/provider/profile');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(['message' => 'Unauthorized'], $this->decodeJson($response));
        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertStringContainsString(
            'successor-version',
            (string) $response->headers->get('Link'),
        );
        self::assertFalse($response->headers->has('Sunset'));
    }

    public function testProviderProfilePatchRequiresMobileAuthenticationBeforePayloadValidation(): void
    {
        $response = $this->request(
            'PATCH',
            '/api/v1/provider/profile',
            '{"canDeliver":true,"canTransportPeople":false}'
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(['message' => 'Unauthorized'], $this->decodeJson($response));
    }

    #[DataProvider('adminEndpoints')]
    public function testProviderAdministrationRequiresAdminRole(
        string $method,
        string $uri,
        ?string $content = null
    ): void {
        $response = $this->request($method, $uri, $content);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(['message' => 'Forbidden'], $this->decodeJson($response));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function adminEndpoints(): iterable
    {
        yield 'list' => ['GET', '/api/v1/admin/providers', null];
        yield 'detail' => ['GET', '/api/v1/admin/providers/12', null];
        yield 'status' => [
            'PATCH',
            '/api/v1/admin/providers/12/status',
            '{"validationStatus":"approved"}',
        ];
    }

    public function testDriverRegistrationRejectsInvalidJson(): void
    {
        $response = $this->request('POST', '/api/v1/user/register/driver', '{');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(['message' => 'Invalid JSON body'], $this->decodeJson($response));
    }

    public function testDriverRegistrationRequiresPhoneAndOtp(): void
    {
        $response = $this->request('POST', '/api/v1/user/register/driver', '{}');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(['message' => 'phone et otp sont requis'], $this->decodeJson($response));
    }

    public function testUploadRequiresCategoryAndPublishesCurrentAllowedCategories(): void
    {
        $response = $this->request('POST', '/api/v1/uploads');

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame([
            'message' => 'category est requis',
            'allowedCategories' => [
                'identity_document',
                'driver_license',
                'vehicle_insurance',
                'vehicle_registration',
                'vehicle_registration_front',
                'vehicle_registration_back',
                'vehicle_photo',
                'profile_photo',
            ],
        ], $this->decodeJson($response));
    }

    public function testV2UploadSessionRequiresMobileAuthentication(): void
    {
        $response = $this->request(
            'POST',
            '/api/v2/provider-upload-sessions',
            '{"allowedCategories":["identity_document"]}',
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(['message' => 'Unauthorized'], $this->decodeJson($response));
    }

    public function testV2ProviderSubmissionRequiresMobileAuthentication(): void
    {
        $response = $this->request(
            'POST',
            '/api/v2/provider/applications/01975aa9-df9c-7b25-b797-6b1ca912e68e/submit',
            '{"revision":1,"documentAssetIds":{}}',
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(['message' => 'Unauthorized'], $this->decodeJson($response));
    }

    private function request(string $method, string $uri, ?string $content = null): Response
    {
        $client = static::createClient();
        $client->request(
            $method,
            $uri,
            server: $content === null ? [] : ['CONTENT_TYPE' => 'application/json'],
            content: $content
        );

        return $client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
