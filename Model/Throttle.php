<?php
namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Base;
use Kanboard\Model\SettingModel;

/**
 * Per-user request limits, kept in core's settings table as plain decimal strings and compared in PHP.
 *
 * Not in user_has_metadata: core's user Integrations form saves any posted key there, so a user could
 * reset their own limits. Only admins write settings through core's forms. Rows are read and written
 * directly (not through ConfigModel), so no per-request config cache goes stale.
 *
 * Options, one set per user (see option()):
 * - lease  = expiry: one provider fetch in flight per user. Claimed by a bare INSERT, so the primary key
 *            on `option` decides between racing requests on every database.
 * - fetch  = last fetch time: forecast fetches at least MIN_FETCH_INTERVAL seconds apart.
 * - search = "<windowStart>:<count>": SEARCH_MAX place searches per SEARCH_WINDOW.
 */
class Throttle extends Base
{
    public const LEASE = 'lease';
    public const FETCH = 'fetch';
    public const SEARCH = 'search';

    public const LEASE_TTL = 30;
    public const MIN_FETCH_INTERVAL = 15;
    public const SEARCH_WINDOW = 60;
    public const SEARCH_MAX = 10;

    /** A stamp further ahead than this is treated as absent (clock change or a planted value). */
    private const SKEW = 60;

    /**
     * The settings option for one of a user's limits (<= 37 characters; the column is VARCHAR(100)).
     */
    public static function option(int $userId, string $name): string
    {
        return 'windowsill_throttle_'.$userId.'_'.$name;
    }

    /**
     * Atomic: true when this request now holds the user's refresh lease.
     *
     * @throws \LogicException inside a transaction: a duplicate-key failure would roll it back.
     */
    public function claimRefresh(int $userId, int $now): bool
    {
        if ($this->db->getConnection()->inTransaction()) {
            throw new \LogicException('Windowsill: claimRefresh() must not run inside a transaction');
        }
        if ($this->insertLease($userId, $now)) {
            return true;
        }

        $current = $this->value($userId, self::LEASE);
        if ($current === null) {
            // Released between our insert and our read: one more try.
            return $this->insertLease($userId, $now);
        }
        if (self::stamp($current, $now + self::LEASE_TTL) > $now) {
            return false;
        }

        // Expired, malformed or far in the future: delete exactly what we read, so two requests
        // taking over the same stale lease cannot both win.
        $this->row($userId, self::LEASE)->eq('value', $current)->remove();
        return $this->insertLease($userId, $now);
    }

    public function releaseRefresh(int $userId): void
    {
        $this->row($userId, self::LEASE)->remove();
    }

    public function fetchWaitSeconds(int $userId, int $now): int
    {
        $last = self::stamp($this->value($userId, self::FETCH), $now);
        if ($last === 0) {
            return 0;
        }
        return max(0, min(self::MIN_FETCH_INTERVAL, $last + self::MIN_FETCH_INTERVAL - $now));
    }

    public function markFetch(int $userId, int $now): void
    {
        $this->put($userId, self::FETCH, (string) $now, $now);
    }

    /**
     * Fixed window: true (and counted) while fewer than SEARCH_MAX searches started in the current window.
     */
    public function allowSearch(int $userId, int $now): bool
    {
        $start = $now;
        $count = 0;
        $raw = $this->value($userId, self::SEARCH);
        if ($raw !== null && preg_match('/^(\d{1,10}):(\d{1,3})$/D', $raw, $m)) {
            $windowStart = (int) $m[1];
            if ($windowStart <= $now + self::SKEW && $now - $windowStart < self::SEARCH_WINDOW) {
                $start = $windowStart;
                $count = (int) $m[2];
            }
        }

        if ($count >= self::SEARCH_MAX) {
            return false;
        }
        $this->put($userId, self::SEARCH, $start.':'.($count + 1), $now);
        return true;
    }

    private function insertLease(int $userId, int $now): bool
    {
        return $this->insert($userId, self::LEASE, sprintf('%010d', $now + self::LEASE_TTL), $now);
    }

    /**
     * False when the option already exists (PicoDb answers a duplicate key with false on every engine).
     */
    private function insert(int $userId, string $name, string $value, int $now): bool
    {
        return $this->db->table(SettingModel::TABLE)->insert([
            'option' => self::option($userId, $name),
            'value' => $value,
            'changed_by' => $userId,
            'changed_on' => $now,
        ]);
    }

    /**
     * Upsert without a transaction: insert, and update when another request created the row first.
     */
    private function put(int $userId, string $name, string $value, int $now): void
    {
        if ($this->value($userId, $name) === null && $this->insert($userId, $name, $value, $now)) {
            return;
        }
        $this->row($userId, $name)->update(['value' => $value, 'changed_by' => $userId, 'changed_on' => $now]);
    }

    private function row(int $userId, string $name)
    {
        return $this->db->table(SettingModel::TABLE)->eq('option', self::option($userId, $name));
    }

    private function value(int $userId, string $name): ?string
    {
        $value = $this->row($userId, $name)->findOneColumn('value');
        return is_string($value) ? $value : null;
    }

    /**
     * A stored Unix time no later than $max + SKEW, else 0.
     */
    private static function stamp(?string $raw, int $max): int
    {
        if ($raw === null || !preg_match('/^\d{1,10}$/D', $raw)) {
            return 0;
        }
        $stamp = (int) $raw;
        return $stamp <= $max + self::SKEW ? $stamp : 0;
    }
}
