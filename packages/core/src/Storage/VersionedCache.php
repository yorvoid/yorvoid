<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use YorvoID\Core\Realm;

/**
 * What decisions read, kept against the version of the realm's data it was read at (AUZ-031, D-10):
 * once for the request, and in a shared pool if one is given. A change increments the version, so
 * what was read before it is never asked for again, on any server; nothing waits for an entry to
 * expire. Only data is kept, never a decision: those depend on the moment and the evidence.
 *
 * A pool's keys start with the installation and the realm (QA-015), so neither installations sharing
 * a pool nor realms read each other's entries. A pool that fails is passed over, with a warning: the
 * store is read instead, which is slower and as correct (OPS-041).
 *
 * The pool is trusted as the database is: what's in it decides who holds which role.
 *
 * @api
 */
final class VersionedCache
{
    /** The shape of what's kept: entries of another shape are never read. */
    private const string FORMAT = 'v1';

    /** @var array<string, mixed> What the request read, by realm, area, version and what */
    private array $memo = [];

    /** Once the pool fails, the request reads from the stores. */
    private bool $failed = false;

    /**
     * @param CacheItemPoolInterface|null $pool         Shared by the servers; without one, each request reads once
     * @param Installation|null           $installation Needed with a pool
     */
    public function __construct(
        private readonly ?CacheItemPoolInterface $pool = null,
        private readonly ?Installation $installation = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if (null !== $pool && null === $installation) {
            throw new \InvalidArgumentException('A shared pool needs the installation, which its keys start with.');
        }
    }

    /**
     * What $read gives at this version of the realm's data in the area, read once.
     *
     * @template T
     *
     * @param string                $area  Whose version it is, e.g. "policy"
     * @param string                $what  What's read, e.g. "assignments.<principal>"
     * @param \Closure(): T         $read  Reads it from the store
     * @param \Closure(mixed): bool $valid Whether what the pool holds is what $read gives: anything else is read again
     *
     * @return T
     */
    public function get(Realm $realm, string $area, int $version, string $what, \Closure $read, \Closure $valid): mixed
    {
        $local = \sprintf('%s.%s.%d.%s', $realm->name, $area, $version, $what);

        if (\array_key_exists($local, $this->memo)) {
            /* @var T */
            return $this->memo[$local];
        }

        $pool = $this->failed ? null : $this->pool;

        if (null === $pool) {
            return $this->memo[$local] = $read();
        }

        try {
            $item = $pool->getItem($this->key($realm, $area, $version, $what));
            $value = $item->isHit() ? $item->get() : null;

            if (null !== $value && $valid($value)) {
                /* @var T $value */
                return $this->memo[$local] = $value;
            }
        } catch (\Throwable $e) {
            $this->warn($e);

            return $this->memo[$local] = $read();
        }

        $value = $read();

        try {
            $pool->save($item->set($value));
        } catch (\Throwable $e) {
            $this->warn($e);
        }

        return $this->memo[$local] = $value;
    }

    /**
     * Forgets what the request read: between requests, and between a worker's messages (PLT-030).
     */
    public function reset(): void
    {
        $this->memo = [];
        $this->failed = false;
    }

    /**
     * Installation first, then realm: e.g. "yorvoid.v1.<installation>.acme.policy.7.changes". What's read
     * is hashed when it has more than PSR-6 keys may hold.
     */
    private function key(Realm $realm, string $area, int $version, string $what): string
    {
        \assert(null !== $this->installation);

        return \sprintf('yorvoid.%s.%s.%s.%s.%d.%s', self::FORMAT, $this->installation->id(), $realm->name, $area, $version, 1 === preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $what) ? $what : hash('sha256', $what));
    }

    private function warn(\Throwable $e): void
    {
        $this->failed = true;
        $this->logger->warning('YorvoID\'s cache failed, so it reads from the stores until the request ends: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
    }
}
