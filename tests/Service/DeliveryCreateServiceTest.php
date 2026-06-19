<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\Pricing\PricingRequest;
use App\Dto\Pricing\PricingResult;
use App\Entity\SubscriptionPlan;
use App\Entity\UserAccount;
use App\Entity\UserSubscription;
use App\Enum\SubscriptionPlanCode;
use App\Enum\UserSubscriptionStatus;
use App\Service\DeliveryCreateService;
use App\Service\Pricing\PricingEngine;
use App\Service\Subscription\PlanLimitChecker;
use App\Service\Subscription\SubscriptionManager;
use App\Service\Subscription\UsageCounterManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryCreateServiceTest extends TestCase
{
    public function testCreatePersistsDeliveryAndConsumesPackagePhoto(): void
    {
        $db = $this->createMock(Connection::class);
        $subscriptions = $this->createMock(SubscriptionManager::class);
        $planLimits = $this->createMock(PlanLimitChecker::class);
        $usageCounters = $this->createMock(UsageCounterManager::class);
        $pricing = $this->createMock(PricingEngine::class);

        $user = (new UserAccount())->setPhone('+224620000001');
        $subscription = $this->activeSubscription($user);

        $subscriptions->method('getUser')->with(42)->willReturn($user);
        $subscriptions->method('getActiveSubscription')->with($user)->willReturn($subscription);
        $planLimits->expects(self::once())->method('assertCanCreateDelivery')->with($user);
        $usageCounters->expects(self::once())
            ->method('incrementDeliveriesCreated')
            ->with($user, $subscription);

        $pricing->expects(self::once())
            ->method('calculate')
            ->willReturnCallback(function (PricingRequest $request): PricingResult {
                self::assertSame('STANDARD', $request->serviceType);
                self::assertSame('MOTO', $request->vehicleType);
                self::assertSame('USER', $request->customerType);
                self::assertGreaterThan(0, $request->distanceKm);

                return new PricingResult(
                    distance: round($request->distanceKm, 1),
                    duration: $request->durationMinutes,
                    basePrice: 1000,
                    distancePrice: 500,
                    surcharges: [],
                    totalPrice: 1500,
                    currency: 'GNF',
                    pricingModelId: 1,
                    pricingRuleId: 2,
                );
            });

        $db->expects(self::once())->method('beginTransaction');
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('rollBack');

        $db->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $params): array|false {
                if (str_contains($sql, 'FROM address a')) {
                    if ($params['addressId'] === 12) {
                        return [
                            'address_id' => 12,
                            'address_name' => 'Maison',
                            'latitude' => 9.6412,
                            'longitude' => -13.5784,
                            'zone_admin_area_id' => null,
                            'zone_name' => null,
                        ];
                    }

                    return [
                        'address_id' => 45,
                        'address_name' => 'Bureau',
                        'latitude' => 9.69,
                        'longitude' => -13.52,
                        'zone_admin_area_id' => null,
                        'zone_name' => null,
                    ];
                }

                if (str_contains($sql, 'FROM uploaded_asset')) {
                    return [
                        'id' => 987,
                        'category' => 'package_photo',
                        'consumed_at' => null,
                        'validation_status' => 'VALID',
                    ];
                }

                return false;
            });

        $executedStatements = [];
        $db->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$executedStatements): int {
                $executedStatements[] = $sql;

                if (str_contains($sql, 'UPDATE uploaded_asset')) {
                    self::assertSame(987, $params['assetId']);
                    return 1;
                }

                return 1;
            });

        $db->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params = []): mixed {
                if (str_contains($sql, 'FROM service_types')) {
                    return 1;
                }

                if (str_contains($sql, 'FROM vehicle_types')) {
                    return 1;
                }

                if (str_contains($sql, 'FROM customer_types')) {
                    return 'USER';
                }

                if (str_contains($sql, 'FROM zones WHERE admin_area_id')) {
                    return false;
                }

                if (str_contains($sql, 'INSERT INTO delivery_order')) {
                    return 123;
                }

                return false;
            });

        $result = $this->service($db, $pricing, $subscriptions, $planLimits, $usageCounters)->create(42, [
            'pickupAddressId' => 12,
            'dropoffAddressId' => 45,
            'serviceType' => 'STANDARD',
            'vehicleType' => 'MOTO',
            'recipient' => [
                'name' => 'Mamadou Diallo',
                'phone' => '+224 620 123 456',
            ],
            'package' => [
                'description' => 'Documents',
                'fragile' => false,
                'photoAssetId' => 987,
            ],
        ]);

        self::assertSame('QUOTED', $result['status']);
        self::assertSame(987, $result['package']['photoAssetId']);
        self::assertSame('224620123456', $result['recipient']['phone']);
        self::assertSame(1500, $result['pricing']['totalAmount']);
        self::assertTrue($this->statementExecuted($executedStatements, 'INSERT INTO delivery_package'));
        self::assertTrue($this->statementExecuted($executedStatements, 'INSERT INTO delivery_pricing_snapshot'));
        self::assertTrue($this->statementExecuted($executedStatements, 'INSERT INTO delivery_status_history'));
        self::assertTrue($this->statementExecuted($executedStatements, 'UPDATE uploaded_asset'));
    }

    public function testCreateRejectsAlreadyConsumedPackagePhoto(): void
    {
        $db = $this->createMock(Connection::class);
        $subscriptions = $this->createMock(SubscriptionManager::class);
        $planLimits = $this->createMock(PlanLimitChecker::class);
        $usageCounters = $this->createMock(UsageCounterManager::class);
        $pricing = $this->createMock(PricingEngine::class);

        $user = (new UserAccount())->setPhone('+224620000001');
        $subscription = $this->activeSubscription($user);

        $subscriptions->method('getUser')->willReturn($user);
        $subscriptions->method('getActiveSubscription')->willReturn($subscription);
        $planLimits->expects(self::once())->method('assertCanCreateDelivery')->with($user);
        $usageCounters->expects(self::never())->method('incrementDeliveriesCreated');
        $pricing->expects(self::never())->method('calculate');
        $db->expects(self::never())->method('beginTransaction');

        $db->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 12,
                    'address_name' => 'Maison',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                ],
                [
                    'address_id' => 45,
                    'address_name' => 'Bureau',
                    'latitude' => 9.69,
                    'longitude' => -13.52,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                ],
                [
                    'id' => 987,
                    'category' => 'package_photo',
                    'consumed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'validation_status' => 'VALID',
                ],
            );

        $db->method('fetchOne')
            ->willReturnCallback(static function (string $sql): mixed {
                if (str_contains($sql, 'FROM service_types')) {
                    return 1;
                }

                if (str_contains($sql, 'FROM vehicle_types')) {
                    return 1;
                }

                return false;
            });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('package.photoAssetId est invalide');

        $this->service($db, $pricing, $subscriptions, $planLimits, $usageCounters)->create(42, [
            'pickupAddressId' => 12,
            'dropoffAddressId' => 45,
            'serviceType' => 'STANDARD',
            'vehicleType' => 'MOTO',
            'package' => [
                'photoAssetId' => 987,
            ],
        ]);
    }

    public function testCreateRejectsPackagePhotoAssetWithWrongCategory(): void
    {
        $db = $this->createMock(Connection::class);
        $subscriptions = $this->createMock(SubscriptionManager::class);
        $planLimits = $this->createMock(PlanLimitChecker::class);
        $usageCounters = $this->createMock(UsageCounterManager::class);
        $pricing = $this->createMock(PricingEngine::class);

        $user = (new UserAccount())->setPhone('+224620000001');
        $subscription = $this->activeSubscription($user);

        $subscriptions->method('getUser')->willReturn($user);
        $subscriptions->method('getActiveSubscription')->willReturn($subscription);
        $planLimits->expects(self::once())->method('assertCanCreateDelivery')->with($user);
        $usageCounters->expects(self::never())->method('incrementDeliveriesCreated');
        $pricing->expects(self::never())->method('calculate');
        $db->expects(self::never())->method('beginTransaction');

        $db->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    'address_id' => 12,
                    'address_name' => 'Maison',
                    'latitude' => 9.6412,
                    'longitude' => -13.5784,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                ],
                [
                    'address_id' => 45,
                    'address_name' => 'Bureau',
                    'latitude' => 9.69,
                    'longitude' => -13.52,
                    'zone_admin_area_id' => null,
                    'zone_name' => null,
                ],
                [
                    'id' => 987,
                    'category' => 'vehicle_photo',
                    'consumed_at' => null,
                    'validation_status' => 'VALID',
                ],
            );

        $db->method('fetchOne')
            ->willReturnCallback(static function (string $sql): mixed {
                if (str_contains($sql, 'FROM service_types')) {
                    return 1;
                }

                if (str_contains($sql, 'FROM vehicle_types')) {
                    return 1;
                }

                return false;
            });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('package.photoAssetId est invalide');

        $this->service($db, $pricing, $subscriptions, $planLimits, $usageCounters)->create(42, [
            'pickupAddressId' => 12,
            'dropoffAddressId' => 45,
            'serviceType' => 'STANDARD',
            'vehicleType' => 'MOTO',
            'package' => [
                'photoAssetId' => 987,
            ],
        ]);
    }

    private function service(
        Connection $db,
        PricingEngine $pricing,
        SubscriptionManager $subscriptions,
        PlanLimitChecker $planLimits,
        UsageCounterManager $usageCounters,
    ): DeliveryCreateService {
        return new DeliveryCreateService($db, $pricing, $subscriptions, $planLimits, $usageCounters);
    }

    private function activeSubscription(UserAccount $user): UserSubscription
    {
        $plan = (new SubscriptionPlan())
            ->setCode(SubscriptionPlanCode::FREE)
            ->setName('Free');

        return (new UserSubscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus(UserSubscriptionStatus::ACTIVE);
    }

    /**
     * @param list<string> $statements
     */
    private function statementExecuted(array $statements, string $needle): bool
    {
        foreach ($statements as $statement) {
            if (str_contains($statement, $needle)) {
                return true;
            }
        }

        return false;
    }
}
