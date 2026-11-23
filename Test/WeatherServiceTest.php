<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\SettingModel;
use Kanboard\Plugin\Windowsill\Model\Budget;
use Kanboard\Plugin\Windowsill\Model\ForecastCache;
use Kanboard\Plugin\Windowsill\Model\OpenMeteoClient;
use Kanboard\Plugin\Windowsill\Model\ProviderException;
use Kanboard\Plugin\Windowsill\Model\Throttle;
use Kanboard\Plugin\Windowsill\Model\WeatherService;
use Kanboard\Plugin\Windowsill\Plugin;
use Psr\Log\LoggerInterface;

class WeatherServiceTest extends Base
{
    private const NOW = 1800000000;
    private const BERLIN = ['name' => 'Berlin', 'admin1' => 'Land Berlin', 'country_code' => 'DE', 'lat' => 52.52, 'lon' => 13.41];
    private const PARIS = ['name' => 'Paris', 'admin1' => 'Île-de-France', 'country_code' => 'FR', 'lat' => 48.85, 'lon' => 2.35];

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
        $this->userId = (int) $this->container['userModel']->create(['username' => 'weather']);
    }

    private static function forecast(float $temp = 10.5): array
    {
        $day = fn (string $date) => ['date' => $date, 'code' => 3, 'max' => 12.5, 'min' => 4.0, 'pop' => 20];
        return [
            'utc_offset' => 3600,
            'current' => ['temp' => $temp, 'feels' => 8.5, 'code' => 3, 'wind' => 11.2, 'is_day' => true],
            'days' => [$day('2027-01-15'), $day('2027-01-16'), $day('2027-01-17')],
        ];
    }

    /**
     * Replace the client with a mock; $calls is how many forecast() calls are expected.
     */
    private function client(int $calls, ?array $result = null, bool $throws = false)
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $method = $client->expects($this->exactly($calls))->method('forecast');
        if ($throws) {
            $method->willThrowException(new ProviderException('Forecast request failed'));
        } else {
            $method->willReturn($result ?? self::forecast());
        }
        $this->container['windowsillClient'] = $client;
        return $client;
    }

    private function saveBerlin(): array
    {
        return $this->container['windowsillLocation']->save($this->userId, self::BERLIN);
    }

    private function writeCache(array $location, int $fetchedAt, ?array $forecast = null): void
    {
        $ok = $this->container['windowsillCache']->write(
            $this->userId, $forecast ?? self::forecast(), $fetchedAt, ForecastCache::keyFor($location)
        );
        $this->assertTrue($ok);
    }

    private function service(): WeatherService
    {
        return $this->container['windowsillService'];
    }

    public function testContainerRegistersService(): void
    {
        $this->assertInstanceOf(WeatherService::class, $this->container['windowsillService']);
    }

    public function testUnsetWithoutLocation(): void
    {
        $this->client(0);
        foreach ([$this->service()->cached($this->userId, self::NOW), $this->service()->refresh($this->userId, self::NOW)] as $vm) {
            $this->assertSame([
                'state' => 'unset', 'location' => null, 'forecast' => null,
                'fetched_at' => null, 'aged' => false, 'now' => self::NOW, 'retry_after' => 0,
            ], $vm);
        }
    }

    public function testFreshFromCacheWithoutNetwork(): void
    {
        $this->client(0);
        $location = $this->saveBerlin();
        $this->writeCache($location, self::NOW - 60);

        foreach ([$this->service()->cached($this->userId, self::NOW), $this->service()->refresh($this->userId, self::NOW)] as $vm) {
            $this->assertSame('fresh', $vm['state']);
            $this->assertSame($location, $vm['location']);
            $this->assertEquals(self::forecast(), $vm['forecast']);
            $this->assertSame(self::NOW - 60, $vm['fetched_at']);
            $this->assertFalse($vm['aged']);
            $this->assertSame(self::NOW, $vm['now']);
        }
    }

    public function testCachedReportsPendingWhenStale(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW - 1801);

        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertEquals(self::forecast(), $vm['forecast']);
        $this->assertSame(self::NOW - 1801, $vm['fetched_at']);
        $this->assertTrue($vm['aged']);
    }

    public function testRefreshFetchesAndCaches(): void
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $client->expects($this->once())->method('forecast')->with(52.52, 13.41, 'metric')->willReturn(self::forecast());
        $this->container['windowsillClient'] = $client;
        $this->saveBerlin();

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('fresh', $vm['state']);
        $this->assertEquals(self::forecast(), $vm['forecast']);
        $this->assertSame(self::NOW, $vm['fetched_at']);
        $this->assertFalse($vm['aged']);

        $after = $this->service()->cached($this->userId, self::NOW + 10);
        $this->assertSame('fresh', $after['state']);
        $this->assertSame(self::NOW, $after['fetched_at']);
    }

    public function testLocationChangeInvalidatesCache(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW - 60);
        $this->container['windowsillLocation']->save($this->userId, self::PARIS);

        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertNull($vm['forecast']);
        $this->assertNull($vm['fetched_at']);
        $this->assertFalse($vm['aged']);
    }

    public function testFailureServesStaleThenBacksOff(): void
    {
        $this->client(2, null, true);
        $this->writeCache($this->saveBerlin(), self::NOW - 7200);

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('stale', $vm['state']);
        $this->assertEquals(self::forecast(), $vm['forecast']);
        $this->assertSame(self::NOW - 7200, $vm['fetched_at']);
        $this->assertTrue($vm['aged']);
        $this->assertSame(self::NOW, $this->container['windowsillCache']->failedAt($this->userId));

        $backedOff = $this->service()->refresh($this->userId, self::NOW + 300);
        $this->assertSame('stale', $backedOff['state']);
        $this->assertSame(self::NOW - 7200, $backedOff['fetched_at']);

        $retried = $this->service()->refresh($this->userId, self::NOW + 601);
        $this->assertSame('stale', $retried['state']);
        $this->assertSame(self::NOW + 601, $this->container['windowsillCache']->failedAt($this->userId));
    }

    public function testFailureWithOldCacheIsUnavailable(): void
    {
        $this->client(1, null, true);
        $this->writeCache($this->saveBerlin(), self::NOW - 86401);

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('unavailable', $vm['state']);
        $this->assertNull($vm['forecast']);
        $this->assertNull($vm['fetched_at']);
        $this->assertFalse($vm['aged']);
    }

    public function testCachedDuringBackoffNeverPending(): void
    {
        $this->client(0);
        $this->saveBerlin();
        $this->container['windowsillCache']->markFailed($this->userId, self::NOW - 10);

        $this->assertSame('unavailable', $this->service()->cached($this->userId, self::NOW)['state']);
    }

    public function testFutureFailureStampBeyondSkewIsIgnored(): void
    {
        $this->client(1);
        $this->saveBerlin();
        $this->container['windowsillCache']->markFailed($this->userId, self::NOW + 3600);

        $this->assertSame('fresh', $this->service()->refresh($this->userId, self::NOW)['state']);
    }

    public function testFutureFailureStampWithinSkewStillBacksOff(): void
    {
        $this->client(0);
        $this->saveBerlin();
        $this->container['windowsillCache']->markFailed($this->userId, self::NOW + 30);

        $this->assertSame('unavailable', $this->service()->refresh($this->userId, self::NOW)['state']);
    }

    public function testBoundaries(): void
    {
        $this->client(1);
        $location = $this->saveBerlin();

        $this->writeCache($location, self::NOW - (WeatherService::FRESH - 1));
        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('fresh', $vm['state']);
        $this->assertFalse($vm['aged']);

        $this->writeCache($location, self::NOW - WeatherService::FRESH);
        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertTrue($vm['aged']);

        $this->container['windowsillCache']->markFailed($this->userId, self::NOW - WeatherService::BACKOFF);
        $this->assertSame('fresh', $this->service()->refresh($this->userId, self::NOW)['state']);
        $this->assertSame(0, $this->container['windowsillCache']->failedAt($this->userId));
    }

    public function testStaleMaxBoundaryDuringBackoff(): void
    {
        $this->client(0);
        $location = $this->saveBerlin();
        $this->container['windowsillCache']->markFailed($this->userId, self::NOW - 10);

        $this->writeCache($location, self::NOW - (WeatherService::STALE_MAX - 1));
        $this->assertSame('stale', $this->service()->cached($this->userId, self::NOW)['state']);

        $this->writeCache($location, self::NOW - WeatherService::STALE_MAX);
        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('unavailable', $vm['state']);
        $this->assertNull($vm['forecast']);
    }

    public function testFutureCacheIsAMiss(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW + 3600);

        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertNull($vm['forecast']);
    }

    public function testSmallClockSkewStillCounts(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW + 60);

        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('fresh', $vm['state']);
        $this->assertFalse($vm['aged']);
    }

    public function testFailureThenLocationChangeFetchesAtOnce(): void
    {
        $client = $this->createMock(OpenMeteoClient::class);
        $client->expects($this->exactly(3))->method('forecast')->willReturnOnConsecutiveCalls(
            $this->throwException(new ProviderException('Forecast request failed')),
            $this->throwException(new ProviderException('Forecast request failed')),
            self::forecast()
        );
        $this->container['windowsillClient'] = $client;
        $this->saveBerlin();

        $this->assertSame('unavailable', $this->service()->refresh($this->userId, self::NOW)['state']);

        $this->container['windowsillLocation']->save($this->userId, self::PARIS);
        $this->assertSame('unavailable', $this->service()->refresh($this->userId, self::NOW + 15)['state']);

        $this->container['windowsillLocation']->setUnits($this->userId, 'us');
        $this->assertSame('fresh', $this->service()->refresh($this->userId, self::NOW + 30)['state']);
    }

    public function testUnstorableResultBacksOff(): void
    {
        $this->client(1);
        $cache = $this->getMockBuilder(ForecastCache::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['write'])
            ->getMock();
        $cache->method('write')->willReturn(false);
        $this->container['windowsillCache'] = $cache;
        $this->saveBerlin();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Windowsill: could not store forecast');
        $this->container['logger'] = $logger;

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('fresh', $vm['state']);
        $this->assertEquals(self::forecast(), $vm['forecast']);
        $this->assertSame(self::NOW, $vm['fetched_at']);
        $this->assertSame(self::NOW, $this->container['windowsillCache']->failedAt($this->userId));

        $after = $this->service()->cached($this->userId, self::NOW + 1);
        $this->assertContains($after['state'], ['unavailable', 'stale']);
    }

    public function testRefreshIsPendingWhileAnotherRefreshHoldsTheLease(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW - 7200);
        $this->assertNotNull($this->container['windowsillThrottle']->claimRefresh($this->userId, self::NOW));

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertGreaterThanOrEqual(2, $vm['retry_after']);
        $this->assertLessThanOrEqual(15, $vm['retry_after']);
        $this->assertEquals(self::forecast(), $vm['forecast'], 'the usable cached forecast stays on screen');
        $this->assertSame(self::NOW - 7200, $vm['fetched_at']);
    }

    public function testUnitToggleWithinFetchSpacingIsPending(): void
    {
        $this->client(1);
        $this->saveBerlin();
        $this->assertSame('fresh', $this->service()->refresh($this->userId, self::NOW)['state']);

        $this->container['windowsillLocation']->setUnits($this->userId, 'us');
        $vm = $this->service()->refresh($this->userId, self::NOW + 10);
        $this->assertSame('pending', $vm['state']);
        $this->assertSame(5, $vm['retry_after']);
        $this->assertNull($vm['forecast'], 'the cache is for the other units');
    }

    public function testFetchAllowedAgainAfterSpacing(): void
    {
        $this->client(2);
        $this->saveBerlin();
        $this->assertSame('fresh', $this->service()->refresh($this->userId, self::NOW)['state']);

        $this->container['windowsillLocation']->setUnits($this->userId, 'us');
        $vm = $this->service()->refresh($this->userId, self::NOW + 15);
        $this->assertSame('fresh', $vm['state']);
        $this->assertSame(0, $vm['retry_after']);
    }

    public function testRetryAfterHasAFloorOfTwoSeconds(): void
    {
        $this->client(0);
        $this->saveBerlin();
        $this->container['windowsillThrottle']->markFetch($this->userId, self::NOW - 14);

        $this->assertSame(2, $this->service()->refresh($this->userId, self::NOW)['retry_after']);
    }

    public function testLeaseIsReleasedAfterProviderFailure(): void
    {
        $this->client(1, null, true);
        $this->saveBerlin();

        $this->assertSame('unavailable', $this->service()->refresh($this->userId, self::NOW)['state']);
        $this->assertNotNull($this->container['windowsillThrottle']->claimRefresh($this->userId, self::NOW + 1));
    }

    public function testCachedNeverThrottlesOrClaims(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW - 1801);
        $this->container['windowsillThrottle']->markFetch($this->userId, self::NOW);

        $vm = $this->service()->cached($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertSame(0, $vm['retry_after']);
        $this->assertNotNull($this->container['windowsillThrottle']->claimRefresh($this->userId, self::NOW));
    }

    private function exhaustGlobalMinute(int $now): void
    {
        $this->assertTrue($this->container['db']->table(SettingModel::TABLE)->insert([
            'option' => 'windowsill_budget_global_m_'.intdiv($now, 60), 'value' => '300', 'changed_by' => 0, 'changed_on' => 0,
        ]));
    }

    public function testGlobalBudgetExhaustedServesStaleWithoutFetching(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW - 7200);
        $this->exhaustGlobalMinute(self::NOW);

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('stale', $vm['state']);
        $this->assertSame(0, $vm['retry_after']);
        $this->assertSame(0, $this->container['windowsillCache']->failedAt($this->userId), 'not marked failed');
        $this->assertNotNull($this->container['windowsillThrottle']->claimRefresh($this->userId, self::NOW), 'lease released');
    }

    public function testGlobalBudgetExhaustedWithoutCacheIsUnavailable(): void
    {
        $this->client(0);
        $this->saveBerlin();
        $this->exhaustGlobalMinute(self::NOW);

        $this->assertSame('unavailable', $this->service()->refresh($this->userId, self::NOW)['state']);
        $this->assertSame(0, $this->container['windowsillCache']->failedAt($this->userId));
    }

    /**
     * Another request fetches between our spacing check and our claim (it released its lease already):
     * the check is repeated once the lease is ours.
     */
    public function testFetchStampedBetweenCheckAndClaimIsPending(): void
    {
        $this->client(0);
        $this->saveBerlin();
        $this->container['windowsillThrottle'] = new class($this->container) extends Throttle {
            public function claimRefresh(int $userId, int $now): ?string
            {
                $this->markFetch($userId, $now);
                return parent::claimRefresh($userId, $now);
            }
        };

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('pending', $vm['state']);
        $this->assertSame(15, $vm['retry_after']);
        $this->assertSame('', (string) $this->container['db']->table(SettingModel::TABLE)
            ->eq('option', Throttle::option($this->userId, Throttle::LEASE))->findOneColumn('value'), 'lease released');
    }

    public function testUserDailyBudgetExhaustedServesStaleWithoutFetching(): void
    {
        $this->client(0);
        $this->writeCache($this->saveBerlin(), self::NOW - 7200);
        $this->assertTrue($this->container['db']->table(SettingModel::TABLE)->insert([
            'option' => 'windowsill_budget_user_'.$this->userId.'_'.intdiv(self::NOW, 86400),
            'value' => (string) Budget::USER_DAY, 'changed_by' => 0, 'changed_on' => 0,
        ]));

        $vm = $this->service()->refresh($this->userId, self::NOW);
        $this->assertSame('stale', $vm['state']);
        $this->assertSame(0, $this->container['windowsillCache']->failedAt($this->userId), 'not marked failed');
        $this->assertNotNull($this->container['windowsillThrottle']->claimRefresh($this->userId, self::NOW), 'lease released');
    }
}
