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
 * one row, so parallel requests can never pass more than the limit. Older windows of a name are deleted after
 * each successful take.
 *
 * The server's Open-Meteo quota is shared per IP (600 a minute, 10,000 a day); GLOBAL_MINUTE and GLOBAL_DAY stay
 * well under it for every search and forecast fetch combined.
 */
class Budget extends Base
{
    public const GLOBAL_MINUTE = 300;
    public const GLOBAL_DAY = 8000;
    public const USER_SEARCH = 10;

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
        $option = self::PREFIX.$name.'_'.intdiv($now, $window);

        try {
            $taken = $this->increment($option, $limit)
                || (!$this->exists($option) && ($this->insertFirst($option, $now) || $this->increment($option, $limit)));
        } catch (SQLException $e) {
            // A value an admin made non-numeric: MySQL (strict) and Postgres reject the cast. Refuse this window.
            return false;
        }

        if ($taken) {
            $this->removeOlderWindows($name, $option);
        }
        return $taken;
    }

    /**
     * One provider call against the server-wide minute and day budgets. When the day refuses after the minute
     * took, that minute slot stays spent.
     */
    public function takeProviderCall(int $now): bool
    {
        return $this->take('global_m', self::GLOBAL_MINUTE, 60, $now)
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

    private function exists(string $option): bool
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

    private function removeOlderWindows(string $name, string $current): void
    {
        // '_' is a LIKE wildcard: escaped, so search_1 never matches search_10's rows.
        $pattern = str_replace('_', '!_', self::PREFIX.$name.'_').'%';
        $this->db->execute(
            'DELETE FROM '.$this->db->escapeIdentifier(SettingModel::TABLE)
            .' WHERE '.$this->db->escapeIdentifier('option')." LIKE ? ESCAPE '!'"
            .' AND '.$this->db->escapeIdentifier('option').' <> ?',
            [$pattern, $current]
        );
    }
}
