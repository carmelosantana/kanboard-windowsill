<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Windowsill\Model\Throttle;
use Kanboard\Plugin\Windowsill\Plugin;

class ThrottleTest extends Base
{
    private const NOW = 1800000000;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
        $this->userId = $this->user('throttled');
    }

    private function user(string $name): int
    {
        return (int) $this->container['userModel']->create(['username' => $name]);
    }

    private function throttle(): Throttle
    {
        return $this->container['windowsillThrottle'];
    }

    private function meta(string $key): string
    {
        return (string) $this->container['userMetadataModel']->get($this->userId, $key, '');
    }

    public function testContainerRegistersThrottle(): void
    {
        $this->assertInstanceOf(Throttle::class, $this->container['windowsillThrottle']);
    }

    public function testSecondClaimWhileHeldIsRefused(): void
    {
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->assertSame('1800000030', $this->meta(Throttle::LEASE_KEY));
        $this->assertFalse($this->throttle()->claimRefresh($this->userId, self::NOW + 1));
        $this->assertFalse($this->throttle()->claimRefresh($this->userId, self::NOW + Throttle::LEASE_TTL - 1));
    }

    public function testReleaseLetsTheNextClaimIn(): void
    {
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->throttle()->releaseRefresh($this->userId);
        $this->assertSame('', $this->meta(Throttle::LEASE_KEY));
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW + 1));
    }

    public function testExpiredLeaseIsTakenOver(): void
    {
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW + 31));
        $this->assertSame('1800000061', $this->meta(Throttle::LEASE_KEY));
    }

    public function testMalformedOrFarFutureLeaseIsTakenOver(): void
    {
        foreach (['garbage', '9999999999'] as $planted) {
            $this->container['userMetadataModel']->save($this->userId, [Throttle::LEASE_KEY => $planted]);
            $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW), $planted);
            $this->throttle()->releaseRefresh($this->userId);
        }
    }

    public function testUsersDoNotBlockEachOther(): void
    {
        $other = $this->user('other');
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->assertTrue($this->throttle()->claimRefresh($other, self::NOW));
        $this->assertFalse($this->throttle()->claimRefresh($other, self::NOW));
    }

    public function testClaimInsideATransactionIsALogicError(): void
    {
        $this->container['db']->startTransaction();
        try {
            $this->expectException(\LogicException::class);
            $this->throttle()->claimRefresh($this->userId, self::NOW);
        } finally {
            $this->container['db']->cancelTransaction();
        }
    }

    public function testFetchSpacing(): void
    {
        $this->assertSame(0, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW));

        $this->throttle()->markFetch($this->userId, self::NOW);
        $this->assertSame('1800000000', $this->meta(Throttle::LAST_FETCH_KEY));
        $this->assertSame(15, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW));
        $this->assertSame(1, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW + 14));
        $this->assertSame(0, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW + 15));
    }

    public function testFutureFetchStampIsIgnored(): void
    {
        $this->throttle()->markFetch($this->userId, self::NOW + 61);
        $this->assertSame(0, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW));
    }

    public function testFetchStampWithinSkewWaitsAtMostTheInterval(): void
    {
        $this->throttle()->markFetch($this->userId, self::NOW + 30);
        $this->assertSame(Throttle::MIN_FETCH_INTERVAL, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW));
    }

    public function testSearchWindow(): void
    {
        for ($i = 0; $i < Throttle::SEARCH_MAX; $i++) {
            $this->assertTrue($this->throttle()->allowSearch($this->userId, self::NOW + $i), "search $i");
        }
        $this->assertSame('1800000000:10', $this->meta(Throttle::SEARCH_KEY));
        $this->assertFalse($this->throttle()->allowSearch($this->userId, self::NOW + 59));
        $this->assertSame('1800000000:10', $this->meta(Throttle::SEARCH_KEY));

        $this->assertTrue($this->throttle()->allowSearch($this->userId, self::NOW + 60));
        $this->assertSame('1800000060:1', $this->meta(Throttle::SEARCH_KEY));
    }

    public function testMalformedSearchValueStartsANewWindow(): void
    {
        foreach (['garbage', '1800000000:', ':3', '1800000000:10:1'] as $planted) {
            $this->container['userMetadataModel']->save($this->userId, [Throttle::SEARCH_KEY => $planted]);
            $this->assertTrue($this->throttle()->allowSearch($this->userId, self::NOW), $planted);
            $this->assertSame('1800000000:1', $this->meta(Throttle::SEARCH_KEY), $planted);
        }
    }

    public function testLocationChangesLeaveThrottleKeysInPlace(): void
    {
        $store = $this->container['windowsillLocation'];
        $store->save($this->userId, ['name' => 'Berlin', 'admin1' => '', 'country_code' => 'DE', 'lat' => 52.52, 'lon' => 13.41]);
        $this->assertTrue($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->throttle()->markFetch($this->userId, self::NOW);
        $this->assertTrue($this->throttle()->allowSearch($this->userId, self::NOW));
        $expected = [
            Throttle::LEASE_KEY => '1800000030',
            Throttle::LAST_FETCH_KEY => '1800000000',
            Throttle::SEARCH_KEY => '1800000000:1',
        ];

        $store->setUnits($this->userId, 'us');
        $store->save($this->userId, ['name' => 'Paris', 'admin1' => '', 'country_code' => 'FR', 'lat' => 48.85, 'lon' => 2.35]);
        $store->clear($this->userId);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $this->meta($key), $key);
        }
    }
}
