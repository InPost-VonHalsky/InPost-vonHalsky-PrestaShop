<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Auth;

/**
 * File-based lock for token refresh operations.
 * Prevents race conditions when multiple processes try to refresh tokens simultaneously.
 *
 * InPost OAuth2 uses refresh token rotation - each refresh token is single-use.
 * When two processes try to refresh concurrently, one succeeds and invalidates
 * the token the other is trying to use. This lock ensures only one process
 * performs the refresh at a time.
 */
class TokenRefreshLock
{
    private const LOCK_FILE = 'inpost_token_refresh.lock';
    private const LOCK_TIMEOUT = 10;

    private string $lockPath;
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct()
    {
        $this->lockPath = _PS_CACHE_DIR_ . self::LOCK_FILE;
    }

    /**
     * Acquire exclusive lock for token refresh.
     *
     * @param int $timeout Maximum seconds to wait for lock
     * @return bool True if lock acquired, false if timeout
     */
    public function acquire(int $timeout = self::LOCK_TIMEOUT): bool
    {
        $this->lockHandle = @fopen($this->lockPath, 'c');

        if ($this->lockHandle === false) {
            return false;
        }

        $startTime = time();

        while (true) {
            if (flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                ftruncate($this->lockHandle, 0);
                fwrite($this->lockHandle, (string) getmypid());
                fflush($this->lockHandle);
                return true;
            }

            if ((time() - $startTime) >= $timeout) {
                fclose($this->lockHandle);
                $this->lockHandle = null;
                return false;
            }

            usleep(100000);
        }
    }

    /**
     * Release the lock.
     */
    public function release(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
