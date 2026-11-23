<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\SettingModel;
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

    /** The stored throttle value for this user ('' when absent), straight from the settings table. */
    private function stored(string $name): string
    {
        $value = $this->container['db']->table(SettingModel::TABLE)
            ->eq('option', Throttle::option($this->userId, $name))->findOneColumn('value');
        return is_string($value) ? $value : '';
    }

    private function plant(string $name, string $value): void
    {
        $this->container['db']->table(SettingModel::TABLE)->eq('option', Throttle::option($this->userId, $name))->remove();
        $this->assertTrue($this->container['db']->table(SettingModel::TABLE)->insert([
            'option' => Throttle::option($this->userId, $name), 'value' => $value, 'changed_by' => 0, 'changed_on' => 0,
        ]));
    }

    public function testContainerRegistersThrottle(): void
    {
        $this->assertInstanceOf(Throttle::class, $this->container['windowsillThrottle']);
    }

    public function testSecondClaimWhileHeldIsRefused(): void
    {
        $this->assertSame('1800000030', $this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->assertSame('1800000030', $this->stored(Throttle::LEASE));
        $this->assertNull($this->throttle()->claimRefresh($this->userId, self::NOW + 1));
        $this->assertNull($this->throttle()->claimRefresh($this->userId, self::NOW + Throttle::LEASE_TTL - 1));
    }

    public function testReleaseLetsTheNextClaimIn(): void
    {
        $lease = $this->throttle()->claimRefresh($this->userId, self::NOW);
        $this->throttle()->releaseRefresh($this->userId, $lease);
        $this->assertSame('', $this->stored(Throttle::LEASE));
        $this->assertSame('1800000031', $this->throttle()->claimRefresh($this->userId, self::NOW + 1));
    }

    public function testExpiredLeaseIsTakenOver(): void
    {
        $this->assertNotNull($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->assertSame('1800000061', $this->throttle()->claimRefresh($this->userId, self::NOW + 31));
        $this->assertSame('1800000061', $this->stored(Throttle::LEASE));
    }

    public function testStaleHolderDoesNotReleaseANewerLease(): void
    {
        $stale = $this->throttle()->claimRefresh($this->userId, self::NOW);
        $newer = $this->throttle()->claimRefresh($this->userId, self::NOW + 31);
        $this->assertNotSame($stale, $newer);

        $this->throttle()->releaseRefresh($this->userId, $stale);
        $this->assertSame($newer, $this->stored(Throttle::LEASE));
        $this->assertNull($this->throttle()->claimRefresh($this->userId, self::NOW + 32));
    }

    public function testMalformedOrFarFutureLeaseIsTakenOver(): void
    {
        foreach (['garbage', '9999999999'] as $planted) {
            $this->plant(Throttle::LEASE, $planted);
            $lease = $this->throttle()->claimRefresh($this->userId, self::NOW);
            $this->assertSame('1800000030', $lease, $planted);
            $this->throttle()->releaseRefresh($this->userId, $lease);
        }
    }

    public function testUsersDoNotBlockEachOther(): void
    {
        $other = $this->user('other');
        $this->assertNotNull($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->assertNotNull($this->throttle()->claimRefresh($other, self::NOW));
        $this->assertNull($this->throttle()->claimRefresh($other, self::NOW));
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
        $this->assertSame('1800000000', $this->stored(Throttle::FETCH));
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

    public function testLocationChangesLeaveThrottleKeysInPlace(): void
    {
        $store = $this->container['windowsillLocation'];
        $store->save($this->userId, ['name' => 'Berlin', 'admin1' => '', 'country_code' => 'DE', 'lat' => 52.52, 'lon' => 13.41]);
        $this->assertNotNull($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->throttle()->markFetch($this->userId, self::NOW);
        $expected = [
            Throttle::LEASE => '1800000030',
            Throttle::FETCH => '1800000000',
        ];

        $store->setUnits($this->userId, 'us');
        $store->save($this->userId, ['name' => 'Paris', 'admin1' => '', 'country_code' => 'FR', 'lat' => 48.85, 'lon' => 2.35]);
        $store->clear($this->userId);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $this->stored($key), $key);
        }
    }

    public function testOptionNamesAreScopedPerUser(): void
    {
        $this->assertSame('windowsill_throttle_42_lease', Throttle::option(42, Throttle::LEASE));
        $this->assertSame('windowsill_throttle_42_fetch', Throttle::option(42, Throttle::FETCH));
    }

    public function testNothingIsKeptInUserMetadata(): void
    {
        $this->assertNotNull($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->throttle()->markFetch($this->userId, self::NOW);

        $this->assertSame([], $this->container['userMetadataModel']->getAll($this->userId));
    }

    /**
     * Core's user Integrations form saves any posted key into the user's own metadata: none of it may reset a limit.
     */
    public function testIntegrationsFormValuesDoNotResetLimits(): void
    {
        $this->assertNotNull($this->throttle()->claimRefresh($this->userId, self::NOW));
        $this->throttle()->markFetch($this->userId, self::NOW);

        $this->assertTrue($this->container['userMetadataModel']->save($this->userId, [
            'windowsill_search' => '0:0', 'windowsill_fetch' => '0', 'windowsill_lease' => '0',
            'windowsill_last_fetch' => '0',
            'windowsill_throttle_'.$this->userId.'_search' => '0:0',
            'windowsill_throttle_'.$this->userId.'_fetch' => '0',
            'windowsill_throttle_'.$this->userId.'_lease' => '0',
        ]));

        $this->assertSame(14, $this->throttle()->fetchWaitSeconds($this->userId, self::NOW + 1));
        $this->assertNull($this->throttle()->claimRefresh($this->userId, self::NOW + 1));
    }

    public function testSearchCountIsNoLongerKeptByThrottle(): void
    {
        $this->assertFalse(method_exists(Throttle::class, 'allowSearch'));
        $this->assertFalse(defined(Throttle::class.'::SEARCH'));
    }
}
