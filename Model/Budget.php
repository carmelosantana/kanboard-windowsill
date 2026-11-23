<?php
namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SettingModel;
use PicoDb\Driver\Mysql;
use PicoDb\SQLException;

/**
 * Fixed-window request budgets, counted atomically by the database.
 *
 * One row per name and window in core's settings table: option `windowsill_budget_<name>_<floor(now / window)>`,
 * value a plain decimal count. Admission is a single conditional UPDATE (`count < limit`) that must change exactly
 * one row, so parallel requests can never pass more than the limit. After a successful take, the name's windows
 * older than the one before the caller's are deleted (best effort): the current and the immediately preceding
 * window are always kept. A request lives far less than one window (60 s minimum), so no request still in
 * flight can recreate a deleted window and restart its count, and a straggler never deletes a newer window.
 *
 * The server's Open-Meteo quota is shared per IP (600 a minute, 10,000 a day). Every search and forecast fetch
 * takes takeProviderCall(): USER_DAY per user, then GLOBAL_MINUTE and GLOBAL_DAY for the whole server.
 */
class Budget extends Base
{
    public const GLOBAL_MINUTE = 300;
    public const GLOBAL_DAY = 8000;
    public const USER_SEARCH = 10;
    public const USER_DAY = 200;

    /** Option prefix; the full option is at most 18 + 40 + 1 + 19 = 78 characters (the column is VARCHAR(100)). */
    private const PREFIX = 'windowsill_budget_';

    /**
     * True (and counted) while fewer than $limit takes of $name happened in the current window of $window seconds.
     * False inside a transaction: a duplicate-key insert would cancel the caller's transaction.
     */
    public function take(string $name, int $limit, int $window, int $now): bool
    {
        if (!preg_match('/^[a-z0-9_]{1,40}$/D', $name) || $window < 1) {
            throw new \InvalidArgumentException('Windowsill: invalid budget');
        }
        if ($limit < 1 || $this->db->getConnection()->inTransaction()) {
            return false;
        }
        $current = intdiv($now, $window);
        $option = self::PREFIX.$name.'_'.$current;

        try {
            // exists() first, so a refusal at the limit never attempts an insert (Postgres logs duplicate keys);
            // the second increment covers a row another request created after our first one missed.
            $taken = $this->increment($option, $limit)
                || ($this->exists($option)
                    ? $this->increment($option, $limit)
                    : ($this->insertFirst($option, $now) || $this->increment($option, $limit)));
        } catch (SQLException $e) {
            // A value an admin made non-numeric: MySQL (strict) and Postgres reject the cast. Refuse this window.
            return false;
        }

        if ($taken) {
            try {
                $this->removeOlderWindows($name, $current);
            } catch (SQLException $e) {
                // Housekeeping only: the take stands.
            }
        }
        return $taken;
    }

    /**
     * One provider call: the user's daily budget, then the server-wide minute and day budgets. A budget that
     * refuses after an earlier one took leaves the earlier slot spent.
     */
    public function takeProviderCall(int $userId, int $now): bool
    {
        return $this->take('user_'.$userId, self::USER_DAY, 86400, $now)
            && $this->take('global_m', self::GLOBAL_MINUTE, 60, $now)
            && $this->take('global_d', self::GLOBAL_DAY, 86400, $now);
    }

    /**
     * Atomic: count + 1 where count < limit. Admitted only when exactly one row changed (MySQL counts changed
     * rows, and a successful increment always changes the value).
     */
    private function increment(string $option, int $limit): bool
    {
        $mysql = $this->db->getDriver() instanceof Mysql;
        $count = 'CAST('.$this->db->escapeIdentifier('value').($mysql ? ' AS UNSIGNED)' : ' AS INTEGER)');
        $statement = $this->db->execute(
            'UPDATE '.$this->db->escapeIdentifier(SettingModel::TABLE)
            .' SET '.$this->db->escapeIdentifier('value').' = CAST('.$count.' + 1 AS '.($mysql ? 'CHAR' : 'TEXT').')'
            .' WHERE '.$this->db->escapeIdentifier('option').' = ? AND '.$count.' < ?',
            [$option, (string) $limit]
        );
        return $statement !== false && $statement->rowCount() === 1;
    }

    protected function exists(string $option): bool
    {
        return $this->db->table(SettingModel::TABLE)->eq('option', $option)->exists();
    }

    /**
     * The window's first take. False when another request created the row first (duplicate key, every engine).
     * Inserting only after exists() keeps duplicate-key errors (logged by Postgres) to genuine races.
     */
    private function insertFirst(string $option, int $now): bool
    {
        return $this->db->table(SettingModel::TABLE)->insert([
            'option' => $option,
            'value' => '1',
            'changed_by' => 0,
            'changed_on' => $now,
        ]);
    }

    /**
     * Deletes this name's windows numbered below $window - 1, keeping the caller's window and the one before it.
     * Only options of the exact form `<prefix><name>_<digits>` count, so `search_1` never touches `search_1_2`
     * (or `search_10`).
     */
    protected function removeOlderWindows(string $name, int $window): void
    {
        $prefix = self::PREFIX.$name.'_';
        // LIKE only narrows the rows ('_' is a wildcard there, so it may match more); the regex below decides.
        $options = $this->db->table(SettingModel::TABLE)->like('option', $prefix.'%')->findAllByColumn('option');
        $older = [];
        foreach ($options as $option) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d{1,19})$/D', $option, $m) && (int) $m[1] < $window - 1) {
                $older[] = $option;
            }
        }
        if ($older !== []) {
            $this->db->table(SettingModel::TABLE)->in('option', $older)->remove();
        }
    }
}
