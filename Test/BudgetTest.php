<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\SettingModel;
use Kanboard\Plugin\Windowsill\Model\Budget;
use Kanboard\Plugin\Windowsill\Plugin;
use PicoDb\Driver\Sqlite;
use PicoDb\SQLException;

class BudgetTest extends Base
{
    /** Start of a minute and of a day (1800000000 is not a multiple of 86400, so use one that is). */
    private const NOW = 1800057600;

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
    }

    private function budget(): Budget
    {
        return $this->container['windowsillBudget'];
    }

    private function settings()
    {
        return $this->container['db']->table(SettingModel::TABLE);
    }

    /** option => value for this name's budget rows. */
    private function rows(string $name): array
    {
        $rows = [];
        foreach ($this->settings()->like('option', 'windowsill_budget_%')->asc('option')->findAll() as $row) {
            if (str_starts_with($row['option'], 'windowsill_budget_'.$name.'_')
                && ctype_digit(substr($row['option'], strlen('windowsill_budget_'.$name.'_')))) {
                $rows[$row['option']] = $row['value'];
            }
        }
        return $rows;
    }

    private function plant(string $option, string $value): void
    {
        $this->assertTrue($this->settings()->insert(['option' => $option, 'value' => $value, 'changed_by' => 0, 'changed_on' => 0]));
    }

    public function testContainerRegistersBudget(): void
    {
        $this->assertInstanceOf(Budget::class, $this->container['windowsillBudget']);
    }

    public function testConstants(): void
    {
        $this->assertSame(300, Budget::GLOBAL_MINUTE);
        $this->assertSame(8000, Budget::GLOBAL_DAY);
        $this->assertSame(10, Budget::USER_SEARCH);
        $this->assertSame(200, Budget::USER_DAY);
    }

    public function testLimitThenNextWindow(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->budget()->take('t', 3, 60, self::NOW + $i), "take $i");
        }
        $this->assertFalse($this->budget()->take('t', 3, 60, self::NOW + 59));
        $this->assertSame(['windowsill_budget_t_30000960' => '3'], $this->rows('t'));

        $this->assertTrue($this->budget()->take('t', 3, 60, self::NOW + 60));
        $this->assertSame(['windowsill_budget_t_30000961' => '1'], $this->rows('t'), 'the old window row is gone');
    }

    public function testOldWindowRowsAreDeletedAfterATake(): void
    {
        $this->plant('windowsill_budget_t_29999999', '3');
        $this->plant('windowsill_budget_t_30000000', '1');
        $this->assertTrue($this->budget()->take('t', 3, 60, self::NOW));
        $this->assertSame(['windowsill_budget_t_30000960' => '1'], $this->rows('t'));
    }

    public function testCleanupDoesNotTouchNamesSharingAPrefix(): void
    {
        $this->assertTrue($this->budget()->take('search_10', 3, 60, self::NOW));
        $this->assertTrue($this->budget()->take('search_1', 3, 60, self::NOW + 60));
        $this->assertSame(['windowsill_budget_search_10_30000960' => '1'], $this->rows('search_10'));
        $this->assertSame(['windowsill_budget_search_1_30000961' => '1'], $this->rows('search_1'));
    }

    public function testNamesAreIndependent(): void
    {
        $this->assertTrue($this->budget()->take('a', 1, 60, self::NOW));
        $this->assertFalse($this->budget()->take('a', 1, 60, self::NOW));
        $this->assertTrue($this->budget()->take('b', 1, 60, self::NOW));
    }

    public function testValuesStayPlainIntegers(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->budget()->take('n', 11, 60, self::NOW);
        }
        $value = $this->settings()->eq('option', 'windowsill_budget_n_30000960')->findOneColumn('value');
        $this->assertSame('11', $value);
    }

    /**
     * The conditional UPDATE admits only when exactly one row changed. MySQL's rowCount() counts changed rows,
     * not matched ones; a successful increment always changes the value, so this holds on every engine.
     */
    public function testExistingRowIsIncrementedByTheConditionalUpdate(): void
    {
        $this->plant('windowsill_budget_r_30000960', '0');
        $this->assertTrue($this->budget()->take('r', 2, 60, self::NOW));
        $this->assertTrue($this->budget()->take('r', 2, 60, self::NOW));
        $this->assertFalse($this->budget()->take('r', 2, 60, self::NOW));
        $this->assertSame(['windowsill_budget_r_30000960' => '2'], $this->rows('r'));
    }

    /**
     * An admin may write anything into settings. The cast gives 0 on SQLite (counted from there); on MariaDB
     * (strict mode) and Postgres it is an SQL error, which refuses the take. Never an exception either way.
     */
    public function testCorruptedValueNeverThrows(): void
    {
        $this->plant('windowsill_budget_c_30000960', 'abc');
        $taken = $this->budget()->take('c', 3, 60, self::NOW);
        $value = $this->settings()->eq('option', 'windowsill_budget_c_30000960')->findOneColumn('value');

        if ($this->container['db']->getDriver() instanceof Sqlite) {
            $this->assertTrue($taken);
            $this->assertSame('1', $value);
        } else {
            $this->assertFalse($taken);
            $this->assertSame('abc', $value);
        }
        $this->assertTrue($this->budget()->take('c', 3, 60, self::NOW + 60), 'the next window starts clean');
    }

    public function testInsideATransactionRefusesWithoutCancellingIt(): void
    {
        $db = $this->container['db'];
        $db->startTransaction();
        try {
            $this->assertFalse($this->budget()->take('x', 3, 60, self::NOW));
            $this->assertTrue($db->getConnection()->inTransaction());
        } finally {
            $db->cancelTransaction();
        }
        $this->assertSame([], $this->rows('x'));
    }

    public function testRejectsUnsafeNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->budget()->take('a%', 3, 60, self::NOW);
    }

    public function testProviderCallTakesUserDayThenMinuteThenDay(): void
    {
        $this->assertTrue($this->budget()->takeProviderCall(7, self::NOW));
        $this->assertSame(['windowsill_budget_user_7_20834' => '1'], $this->rows('user_7'));
        $this->assertSame(['windowsill_budget_global_m_30000960' => '1'], $this->rows('global_m'));
        $this->assertSame(['windowsill_budget_global_d_20834' => '1'], $this->rows('global_d'));
    }

    public function testProviderCallRefusedAtMinuteLimit(): void
    {
        $this->plant('windowsill_budget_global_m_30000960', (string) Budget::GLOBAL_MINUTE);
        $this->assertFalse($this->budget()->takeProviderCall(7, self::NOW));
        $this->assertSame([], $this->rows('global_d'), 'the day budget is not spent');
    }

    public function testProviderCallRefusedAtDayLimit(): void
    {
        $this->plant('windowsill_budget_global_d_20834', (string) Budget::GLOBAL_DAY);
        $this->assertFalse($this->budget()->takeProviderCall(7, self::NOW));
        $this->assertSame(['windowsill_budget_global_m_30000960' => '1'], $this->rows('global_m'), 'that minute slot is spent');
    }

    public function testOptionNamesFitTheColumn(): void
    {
        // The longest user id; a window number of 2^31 seconds (changed_on is a 32-bit INTEGER on MySQL/Postgres).
        $this->assertTrue($this->budget()->take('search_'.PHP_INT_MAX, 1, 1, 2147483647));
        foreach ($this->settings()->like('option', 'windowsill_budget_%')->findAllByColumn('option') as $option) {
            $this->assertLessThanOrEqual(100, strlen($option));
        }
    }

    public function testProviderCallRefusedAtUserDayLimitSpendsNoGlobalBudget(): void
    {
        $this->plant('windowsill_budget_user_7_20834', (string) Budget::USER_DAY);
        $this->assertFalse($this->budget()->takeProviderCall(7, self::NOW));
        $this->assertSame([], $this->rows('global_m'));
        $this->assertSame([], $this->rows('global_d'));
        $this->assertTrue($this->budget()->takeProviderCall(8, self::NOW), 'another user is not affected');
    }

    /**
     * Race on a window's first take: our increment misses (no row yet), another request inserts the row,
     * then our exists() sees it. We retry the increment instead of refusing at 1 of limit.
     */
    public function testFirstTakeRaceIsAdmittedWhenTheRowAppearsBeforeExists(): void
    {
        $budget = new class($this->container) extends Budget {
            protected function exists(string $option): bool
            {
                $this->db->table(SettingModel::TABLE)->insert(['option' => $option, 'value' => '1', 'changed_by' => 0, 'changed_on' => 0]);
                return parent::exists($option);
            }
        };
        $this->assertTrue($budget->take('race', 3, 60, self::NOW));
        $this->assertSame(['windowsill_budget_race_30000960' => '2'], $this->rows('race'));
    }

    public function testDelayedOldWindowNeverDeletesANewerWindow(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->budget()->take('t', 4, 60, self::NOW + 60));
        }
        $this->assertTrue($this->budget()->take('t', 4, 60, self::NOW), 'a straggler from the previous window');
        $this->assertSame('3', $this->rows('t')['windowsill_budget_t_30000961'] ?? null, 'the newer window survives');

        $this->assertTrue($this->budget()->take('t', 4, 60, self::NOW + 61));
        $this->assertSame(['windowsill_budget_t_30000961' => '4'], $this->rows('t'));
        $this->assertFalse($this->budget()->take('t', 4, 60, self::NOW + 62));
    }

    public function testCleanupFailureDoesNotChangeAdmission(): void
    {
        $budget = new class($this->container) extends Budget {
            protected function removeOlderWindows(string $name, int $window): void
            {
                throw new SQLException('cleanup failed');
            }
        };
        $this->assertTrue($budget->take('f', 1, 60, self::NOW));
        $this->assertFalse($budget->take('f', 1, 60, self::NOW));
    }

    public function testCleanupOnlyTouchesItsOwnName(): void
    {
        $this->assertTrue($this->budget()->take('search_1_2', 3, 60, self::NOW));
        $this->assertTrue($this->budget()->take('search_1', 3, 60, self::NOW));
        $this->assertTrue($this->budget()->take('search_1', 3, 60, self::NOW + 60));
        $this->assertSame(['windowsill_budget_search_1_2_30000960' => '1'], $this->rows('search_1_2'));

        $this->assertTrue($this->budget()->take('search_1_2', 3, 60, self::NOW + 60));
        $this->assertSame(['windowsill_budget_search_1_30000961' => '1'], $this->rows('search_1'));
        $this->assertSame(['windowsill_budget_search_1_2_30000961' => '1'], $this->rows('search_1_2'));
    }
}
