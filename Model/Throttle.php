<?php
namespace Kanboard\Plugin\Windowsill\Model;

use Kanboard\Core\Base;

/**
 * Per-user request limits, kept in user_has_metadata as plain decimal strings and compared in PHP.
 *
 * - Refresh lease (windowsill_lease = expiry): one provider fetch in flight per user. Claimed by a bare
 *   INSERT, so the UNIQUE(user_id, name) key decides between racing requests on every database.
 * - Fetch spacing (windowsill_last_fetch): forecast fetches at least MIN_FETCH_INTERVAL seconds apart.
 * - Search window (windowsill_search = "<windowStart>:<count>"): SEARCH_MAX place searches per SEARCH_WINDOW.
 *
 * LocationStore never removes these keys, so removing a location or toggling units cannot reset a limit.
 */
class Throttle extends Base
{
    public const LEASE_KEY = 'windowsill_lease';
    public const LAST_FETCH_KEY = 'windowsill_last_fetch';
    public const SEARCH_KEY = 'windowsill_search';

    public const LEASE_TTL = 30;
    public const MIN_FETCH_INTERVAL = 15;
    public const SEARCH_WINDOW = 60;
    public const SEARCH_MAX = 10;

    /** Core's UserMetadataModel table (it has no public constant for it). */
    private const TABLE = 'user_has_metadata';

    /** A stamp further ahead than this is treated as absent (clock change or a planted value). */
    private const SKEW = 60;

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

        $current = $this->value($userId, self::LEASE_KEY);
        if ($current === null) {
            // Released between our insert and our read: one more try.
            return $this->insertLease($userId, $now);
        }
        if (self::stamp($current, $now + self::LEASE_TTL) > $now) {
            return false;
        }

        // Expired, malformed or far in the future: delete exactly what we read, so two requests
        // taking over the same stale lease cannot both win.
        $this->leaseRow($userId)->eq('value', $current)->remove();
        return $this->insertLease($userId, $now);
    }

    public function releaseRefresh(int $userId): void
    {
        $this->leaseRow($userId)->remove();
    }

    public function fetchWaitSeconds(int $userId, int $now): int
    {
        $last = self::stamp($this->value($userId, self::LAST_FETCH_KEY), $now);
        if ($last === 0) {
            return 0;
        }
        return max(0, min(self::MIN_FETCH_INTERVAL, $last + self::MIN_FETCH_INTERVAL - $now));
    }

    public function markFetch(int $userId, int $now): void
    {
        $this->userMetadataModel->save($userId, [self::LAST_FETCH_KEY => (string) $now]);
    }

    /**
     * Fixed window: true (and counted) while fewer than SEARCH_MAX searches started in the current window.
     */
    public function allowSearch(int $userId, int $now): bool
    {
        $start = $now;
        $count = 0;
        $raw = $this->value($userId, self::SEARCH_KEY);
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
        $this->userMetadataModel->save($userId, [self::SEARCH_KEY => $start.':'.($count + 1)]);
        return true;
    }

    private function insertLease(int $userId, int $now): bool
    {
        return $this->db->table(self::TABLE)->insert([
            'user_id' => $userId,
            'name' => self::LEASE_KEY,
            'value' => sprintf('%010d', $now + self::LEASE_TTL),
            'changed_by' => $userId,
            'changed_on' => $now,
        ]);
    }

    private function leaseRow(int $userId)
    {
        return $this->db->table(self::TABLE)->eq('user_id', $userId)->eq('name', self::LEASE_KEY);
    }

    private function value(int $userId, string $key): ?string
    {
        $value = $this->db->table(self::TABLE)->eq('user_id', $userId)->eq('name', $key)->findOneColumn('value');
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
