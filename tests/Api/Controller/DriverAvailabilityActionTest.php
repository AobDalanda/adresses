<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\Controller\DriverAvailabilityAction;
use App\Entity\UserAccount;
use App\Security\AuthenticatedIdentity;
use App\Security\AuthenticatedIdentityFactory;
use App\Security\RequestIdentityResolver;
use App\Security\TrackingIdentityResolver;
use App\Service\JwtAuthService;
use App\Service\ProviderProfileService;
use App\Service\Tracking\DriverAvailabilityService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DriverAvailabilityActionTest extends TestCase
{
    public function testGetReturnsOfflineStateForAccount43(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->availabilityRow(false, false, 0));

        $response = $this->action($db)(Request::create('/api/v1/drivers/me/availability', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['online']);
        self::assertFalse($this->json($response)['effectiveOnline']);
    }

    public function testPutOnlineWithoutAvailabilityVersionIsAcceptedWithoutRecentLocation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            $this->profileRow(),
            $this->availabilityRow(true, false, 1),
        );
        $db->expects(self::once())->method('executeStatement');

        $response = $this->action($db)(Request::create(
            '/api/v1/drivers/me/availability',
            'PUT',
            content: '{"online":true}',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['online']);
        self::assertFalse($this->json($response)['effectiveOnline']);
    }

    public function testPutOfflineIsImmediate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            $this->profileRow(),
            $this->availabilityRow(false, false, 4),
        );
        $db->expects(self::once())->method('executeStatement');

        $response = $this->action($db)(Request::create(
            '/api/v1/drivers/me/availability',
            'PUT',
            content: '{"online":false}',
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['online']);
        self::assertFalse($this->json($response)['effectiveOnline']);
    }

    public function testApprovedLegacyProviderWithoutCanonicalProfileReturns404(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'profile_id' => null,
            'legacy_application_status' => 'APPROVED',
        ]);

        $response = $this->action($db)(Request::create(
            '/api/v1/drivers/me/availability',
            'PUT',
            content: '{"online":true}',
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('DRIVER_PROFILE_NOT_FOUND', $this->json($response)['error']);
    }

    public function testIncompleteProfileReturns422(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(array_replace($this->profileRow(), [
            'authorization_id' => null,
        ]));

        $response = $this->action($db)(Request::create(
            '/api/v1/drivers/me/availability',
            'PUT',
            content: '{"online":true}',
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('DRIVER_PROFILE_INCOMPLETE', $this->json($response)['error']);
    }

    public function testNonProviderReturns403(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn([
            'profile_id' => null,
            'legacy_application_status' => null,
        ]);

        $response = $this->action($db)(Request::create(
            '/api/v1/drivers/me/availability',
            'PUT',
            content: '{"online":true}',
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('PROVIDER_NOT_AUTHORIZED', $this->json($response)['error']);
    }

    private function action(Connection $db): DriverAvailabilityAction
    {
        $user = (new UserAccount())->setPhone('33781191499')->setAccountType('client');
        $id = new \ReflectionProperty($user, 'id');
        $id->setValue($user, 43);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new AuthenticatedIdentity(
            $user,
            'mobile',
            ['ROLE_USER', 'ROLE_PROVIDER'],
            ['uid' => 43],
        ));
        $requestIdentities = new RequestIdentityResolver(
            $security,
            $this->createMock(JwtAuthService::class),
            $this->createMock(AuthenticatedIdentityFactory::class),
        );
        $profileDb = $this->createMock(Connection::class);
        $profileDb->method('fetchAssociative')->willReturn([
            'id' => 2,
            'user_id' => 43,
            'can_deliver' => true,
            'can_transport_people' => false,
            'validation_status' => 'approved',
            'created_at' => '2026-09-26 12:00:00',
            'updated_at' => '2026-09-26 12:00:00',
            'phone' => '33781191499',
            'name' => 'Provider 43',
            'email' => null,
            'verified' => true,
            'account_type' => 'client',
        ]);
        $profiles = new ProviderProfileService($profileDb);

        return new DriverAvailabilityAction(
            new TrackingIdentityResolver($requestIdentities, $profiles),
            new DriverAvailabilityService($db),
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private function profileRow(): array
    {
        return [
            'profile_id' => 2,
            'can_deliver' => true,
            'validation_status' => 'approved',
            'authorization_id' => 2,
            'authorization_status' => 'ACTIVE',
            'authorization_can_deliver' => true,
            'legacy_application_status' => 'APPROVED',
        ];
    }

    /** @return array<string, mixed> */
    private function availabilityRow(bool $online, bool $effectiveOnline, int $version): array
    {
        return $this->profileRow() + [
            'requested_online' => $online,
            'effective_online' => $effectiveOnline,
            'availability_version' => $version,
            'changed_at' => null,
            'last_location_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function json(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
