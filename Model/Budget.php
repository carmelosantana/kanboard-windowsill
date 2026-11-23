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
 * that ended at least RETENTION seconds before the caller's window began are deleted (best effort); the
 * immediately preceding window is always kept. A request would have to stall for over an hour between reading
 * the clock and its insert to recreate a deleted window: far beyond SQLite's 30 s busy timeout and InnoDB's 50 s
 * lock wait; Postgres sets no default bound, but a user cannot hold the locks involved, so only a database
 * incident could do it. A straggler never deletes a newer window. Minute budgets keep up to 61
 * rows per name, day budgets two.
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

    /** Seconds of past windows kept after a take: cleanup can only race a request older than this. */
    public const RETENTION = 3600;

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
                $this->removeOlderWindows($name, $current - max(1, intdiv(self::RETENTION, $window)));
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
     * Deletes this name's windows numbered below $keepFrom (the caller passes its window minus
     * max(1, RETENTION / length), so its own window and at least the one before it are kept).
     * Only options of the exact form `<prefix><name>_<digits>` count, so `search_1` never touches `search_1_2`
     * (or `search_10`).
     */
    protected function removeOlderWindows(string $name, int $keepFrom): void
    {
        $prefix = self::PREFIX.$name.'_';
        // LIKE only narrows the rows ('_' is a wildcard there, so it may match more); the regex below decides.
        $options = $this->db->table(SettingModel::TABLE)->like('option', $prefix.'%')->findAllByColumn('option');
        $older = [];
        foreach ($options as $option) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d{1,19})$/D', $option, $m) && (int) $m[1] < $keepFrom) {
                $older[] = $option;
            }
        }
        if ($older !== []) {
            $this->db->table(SettingModel::TABLE)->in('option', $older)->remove();
        }
    }
}
