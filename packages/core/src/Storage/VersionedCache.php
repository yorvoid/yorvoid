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
    private const string FORMAT = 'v2';

    /** A read's name: never data */
    private const string READ = '/^[a-z][a-z_]*$/D';

    /** @var array<string, mixed> What the request read, by realm, area, version, read and arguments */
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
     * What $load gives at this version of the realm's data in the area, read once.
     *
     * The read's arguments are encoded here, never by the caller: as JSON, so two different lists never
     * share an entry, whatever the strings hold (M-4).
     *
     * @template T
     *
     * @param string                $area  Whose version it is, e.g. "policy"
     * @param string                $read  What's read, a name like "assignments": lowercase letters and underscores
     * @param list<string>          $of    Its arguments, in order, e.g. the principal's ID
     * @param \Closure(): T         $load  Reads it from the store
     * @param \Closure(mixed): bool $valid Whether what the pool holds is what $load gives: anything else is read again
     *
     * @return T
     *
     * @throws \LogicException When the read's name or its arguments aren't of that shape
     */
    public function get(Realm $realm, string $area, int $version, string $read, array $of, \Closure $load, \Closure $valid): mixed
    {
        if (1 !== preg_match(self::READ, $read)) {
            throw new \LogicException(\sprintf('"%s" isn\'t the name of a read: use lowercase letters and underscores.', $read));
        }

        self::checkArguments($read, $of);

        $arguments = json_encode($of, \JSON_THROW_ON_ERROR);
        $local = \sprintf("%s.%s.%d.%s\0%s", $realm->name, $area, $version, $read, $arguments);

        if (\array_key_exists($local, $this->memo)) {
            /* @var T */
            return $this->memo[$local];
        }

        $pool = $this->failed ? null : $this->pool;

        if (null === $pool) {
            return $this->memo[$local] = $load();
        }

        try {
            $item = $pool->getItem($this->key($realm, $area, $version, $read, [] === $of ? null : $arguments));
            $value = $item->isHit() ? $item->get() : null;

            if (null !== $value && $valid($value)) {
                /* @var T $value */
                return $this->memo[$local] = $value;
            }
        } catch (\Throwable $e) {
            $this->warn($e);

            return $this->memo[$local] = $load();
        }

        $value = $load();

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
     * Installation first, then realm: e.g. "yorvoid.v2.<installation>.acme.policy.7.assignments-<sha256>".
     * The arguments are always hashed, and a read's name has no "-", so a key reads back one way only.
     */
    private function key(Realm $realm, string $area, int $version, string $read, ?string $arguments): string
    {
        \assert(null !== $this->installation);

        return \sprintf('yorvoid.%s.%s.%s.%s.%d.%s', self::FORMAT, $this->installation->id(), $realm->name, $area, $version, null === $arguments ? $read : $read.'-'.hash('sha256', $arguments));
    }

    /**
     * Only strings encode one way each, so the types are checked as it runs, not only by the docs: an
     * object without public properties would be "{}" whatever it is (C1).
     *
     * @param array<mixed> $of
     */
    private static function checkArguments(string $read, array $of): void
    {
        if (!array_is_list($of) || [] !== array_filter($of, static fn (mixed $argument): bool => !\is_string($argument))) {
            throw new \LogicException(\sprintf('The arguments of the read "%s" must be a list of strings.', $read));
        }
    }

    private function warn(\Throwable $e): void
    {
        $this->failed = true;
        $this->logger->warning('YorvoID\'s cache failed, so it reads from the stores until the request ends: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
    }
}
