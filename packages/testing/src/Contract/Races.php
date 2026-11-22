<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Assert;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\Id;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\IntentVerifier\VerifierState;
use YorvoID\IntentVerifier\VerifierStore;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Testing\Ids;
use YorvoID\Throttle\ThrottleStore;

/**
 * The races of the concurrency contracts, by name: what each process does before the race, and what
 * it does in it. Both run in the race's processes, with the stores the factory gives.
 *
 * @internal For yorvoid/testing's concurrency contracts and Race
 */
final readonly class Races
{
    /**
     * Rounds of each race: a race of one call is decided by the database, so a store that checks and
     * then acts loses only some rounds (R2).
     */
    public const int ROUNDS = 20;

    /**
     * @param \Closure(\Closure(string): object, array<string, int|string>): mixed                  $before
     * @param \Closure(\Closure(string): object, array<string, int|string>, mixed): (bool|int|null) $act
     */
    private function __construct(
        public \Closure $before,
        public \Closure $act,
    ) {
    }

    public static function named(string $name): self
    {
        $nothing = static fn (): null => null;

        return match ($name) {
            // Each finds the token before any deletes it: deleting must be the check.
            'one_time_token.delete' => new self(
                static function (\Closure $get, array $round): bool {
                    $store = $get(OneTimeTokenStore::class);
                    \assert($store instanceof OneTimeTokenStore);

                    if (null === $store->find(new Realm((string) $round['realm']), (string) $round['selector'], (string) $round['purpose'])) {
                        throw new \RuntimeException('The token to race for wasn\'t found.');
                    }

                    return true;
                },
                static function (\Closure $get, array $round): bool {
                    $store = $get(OneTimeTokenStore::class);
                    \assert($store instanceof OneTimeTokenStore);

                    return $store->delete(new Realm((string) $round['realm']), (string) $round['selector']);
                },
            ),
            'recovery_code.use' => new self($nothing, static function (\Closure $get, array $round): bool {
                $store = $get(RecoveryCodeStore::class);
                \assert($store instanceof RecoveryCodeStore);

                return $store->use(new Realm((string) $round['realm']), PrincipalId::fromString((string) $round['principal']), (string) $round['hash']);
            }),
            'setup.claim' => new self($nothing, static function (\Closure $get, array $round): bool {
                $store = $get(SetupStore::class);
                \assert($store instanceof SetupStore);

                return $store->claim(new Realm((string) $round['realm']), (string) $round['hash'], new PrincipalId(Ids::next()), new \DateTimeImmutable((string) $round['at']));
            }),
            'ceremony.take' => new self($nothing, static function (\Closure $get, array $round): bool {
                $store = $get(CeremonyStore::class);
                \assert($store instanceof CeremonyStore);

                return null !== $store->take(new Realm((string) $round['realm']), Id::fromString((string) $round['ceremony']));
            }),
            'throttle.advance' => new self($nothing, static function (\Closure $get, array $round): bool {
                $store = $get(ThrottleStore::class);
                \assert($store instanceof ThrottleStore);

                return null !== $store->advance((string) $round['key'], (int) $round['now'], (int) $round['interval'], (int) $round['latest']);
            }),
            // Each reads the principal before any changes it: the change must check what was read.
            'principal.change_status' => new self(
                static function (\Closure $get, array $round): Principal {
                    $store = $get(PrincipalStore::class);
                    \assert($store instanceof PrincipalStore);

                    return $store->get(new Realm((string) $round['realm']), PrincipalId::fromString((string) $round['principal'])) ?? throw new \RuntimeException('The principal to race for wasn\'t found.');
                },
                static function (\Closure $get, array $round, mixed $before): bool {
                    $store = $get(PrincipalStore::class);
                    \assert($store instanceof PrincipalStore && $before instanceof Principal);

                    return $store->changeStatus($before, $before->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable((string) $round['at'])));
                },
            ),
            'session.end' => new self($nothing, static function (\Closure $get, array $round): bool {
                $store = $get(SessionStore::class);
                \assert($store instanceof SessionStore);

                return $store->end(new Realm((string) $round['realm']), SessionId::fromString((string) $round['session']), new \DateTimeImmutable((string) $round['at']), SessionEndReason::SignedOut);
            }),
            'security_log.append' => new self($nothing, static function (\Closure $get, array $round): int {
                [$store, $transactions] = self::log($get);
                $realm = new Realm((string) $round['realm']);

                return $transactions->run(static fn (): LogEntry => $store->append($realm, self::entry($realm)))->sequence;
            }),
            // Under the realm's lock, each reads how many entries there are, waits a little, and appends:
            // with the lock, no two read the same count.
            'security_log.lock' => new self($nothing, static function (\Closure $get, array $round): int {
                [$store, $transactions] = self::log($get);
                $realm = new Realm((string) $round['realm']);

                return $transactions->run(static function () use ($store, $realm): int {
                    $store->lock($realm);
                    $count = iterator_count($store->entries($realm, 1));
                    usleep(20_000);
                    $store->append($realm, self::entry($realm));

                    return $count;
                });
            }),
            // Each reads the count in the state and writes it back one higher.
            'verifier.count' => new self($nothing, static function (\Closure $get): bool {
                $store = $get(VerifierStore::class);
                \assert($store instanceof VerifierStore);

                return $store->transaction(static function (VerifierState $state): bool {
                    $state->bootstrapSecretHash = str_pad((string) ((int) $state->bootstrapSecretHash + 1), 64, '0', \STR_PAD_LEFT);

                    return true;
                });
            }),
            default => throw new \InvalidArgumentException(\sprintf('There\'s no race "%s".', $name)),
        };
    }

    /**
     * Whether a store with a concurrency contract runs it: in the
     * test case itself, in the one its #[ConcurrencyTestedIn] names, or not at all only with
     * #[InMemoryStores].
     *
     * @param class-string $testCase
     * @param class-string $contract The store's concurrency contract
     *
     * @return string|null What's wrong; null if nothing
     */
    public static function untested(string $testCase, string $contract): ?string
    {
        $class = new \ReflectionClass($testCase);

        if (self::uses($class, $contract) || [] !== $class->getAttributes(InMemoryStores::class)) {
            return null;
        }

        foreach ($class->getAttributes(ConcurrencyTestedIn::class) as $attribute) {
            $other = $attribute->newInstance()->testCase;

            return class_exists($other) && self::uses(new \ReflectionClass($other), $contract) ? null : \sprintf('%s, which #[ConcurrencyTestedIn] names, doesn\'t use %s.', $other, $contract);
        }

        return \sprintf('The store has a concurrency contract, %s: use it in this test case, or name the one that does with #[ConcurrencyTestedIn]. Only stores in memory may go without it (#[InMemoryStores]).', $contract);
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function uses(\ReflectionClass $class, string $trait): bool
    {
        for ($at = $class; false !== $at; $at = $at->getParentClass()) {
            if (\in_array($trait, $at->getTraitNames(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * In every round, exactly $winners of the processes won.
     *
     * @param list<list<mixed>> $rounds
     */
    public static function assertWinners(int $winners, array $rounds, string $what): void
    {
        foreach ($rounds as $round => $results) {
            Assert::assertCount($winners, array_filter($results, static fn (mixed $result): bool => true === $result), \sprintf('Round %d of %d: %s', $round + 1, \count($rounds), $what));
        }
    }

    /**
     * @param \Closure(string): object $get
     *
     * @return array{SecurityLogStore, Transactions}
     */
    private static function log(\Closure $get): array
    {
        $store = $get(SecurityLogStore::class);
        $transactions = $get(Transactions::class);
        \assert($store instanceof SecurityLogStore && $transactions instanceof Transactions);

        return [$store, $transactions];
    }

    /**
     * @return \Closure(int, ?string): LogEntry
     */
    private static function entry(Realm $realm): \Closure
    {
        return static fn (int $sequence, ?string $previous): LogEntry => LogEntry::create($realm, $sequence, Ids::next(), new \DateTimeImmutable(), 'yorvoid.test.race', Outcome::Success, new Origin(OriginKind::Job, 'race'), null, null, null, ['process' => getmypid()], $previous ?? 'genesis');
    }
}
