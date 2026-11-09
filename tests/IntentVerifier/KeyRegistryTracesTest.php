<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Serializer\Serializer;
use YorvoID\IntentVerifier\Base64Url;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\IntentChecks;
use YorvoID\IntentVerifier\IntentRefused;
use YorvoID\IntentVerifier\Key;
use YorvoID\IntentVerifier\KeyChange;
use YorvoID\IntentVerifier\KeyRegistry;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
use YorvoID\IntentVerifier\VerifiedIntent;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\IntentVerifier\VerifierState;
use YorvoID\Tests\Support\Itf\Itf;
use YorvoID\Tests\Support\Itf\ItfState;
use YorvoID\Tests\Support\Itf\ItfVariant;
use YorvoID\Tests\Support\Itf\Replay;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * Replays executions of the formal model of the verifier's keys (spec/verifier/key_registry.qnt,
 * replayed: YorvoID's way, counting the keys already leaving) against KeyRegistry: every change the model
 * asks for, cancels or applies, the registry does; after each step the keys, the waiting changes and the
 * bootstrap secret are the model's; and what the model can't do at that moment, the registry refuses.
 * The signatures are IntentChecks', tested on their own: here a change is signed by a key the model has.
 * One tick of the model's clock is the delay.
 */
final class KeyRegistryTracesTest extends TestCase
{
    private const string TRACES = __DIR__.'/../../spec/verifier/traces.itf.json';

    private const int DELAY = 60;

    public function testTheRegistryBehavesAsTheModel(): void
    {
        $authenticator = new VirtualAuthenticator();
        /** @var array<string, Key> $keys The model's keys, by its name for them */
        $keys = [];

        foreach (['k1', 'k2', 'k3'] as $name) {
            $authenticator->create();
            $passkey = $authenticator->credentials[\count($authenticator->credentials) - 1];
            $keys[$name] = Key::fromParameters(self::parameters($passkey, $name), new \DateTimeImmutable('@0')) ?? throw new \LogicException('Not a key.');
        }

        $steps = 0;

        foreach (Itf::traces(self::TRACES) as $number => $trace) {
            $clock = new MockClock('2026-11-09 18:00:00 UTC');
            $start = $clock->now()->getTimestamp();
            $config = new VerifierConfig('acme', 'sign.example.com', ['https://sign.example.com'], ['https://console.example.com'], keyChangeDelay: self::DELAY);
            $log = new InMemoryVerifierLog();
            $registry = new KeyRegistry($config, $log, new IntentChecks($config, $log, $clock, new Serializer()), $clock);
            $state = new VerifierState();
            $name = static fn (string $id): string => (string) array_search($id, array_map(static fn (Key $key): string => $key->id(), $keys), true);
            $signed = static function (string $action, array $parameters) use ($state, $clock): VerifiedIntent {
                /** @var array<string, mixed> $parameters */
                $at = $clock->now();
                $signer = array_values($state->keys)[0] ?? throw new \LogicException('No key to sign with.');

                return new VerifiedIntent(new Intent($action, $parameters, 'acme', Base64Url::encode(random_bytes(18)), $at, $at->modify('+5 minutes')), $signer, $at);
            };
            $adding = static fn (string $key): Intent => new Intent(OperationPolicy::ADD_KEY, self::parameters($authenticator->credentials[(int) substr($key, 1) - 1], $key), 'acme', Base64Url::encode(random_bytes(18)), $clock->now(), $clock->now()->modify('+5 minutes'));
            $key = static fn (ItfState $step): string => \is_string($step->pick('k')) ? $step->pick('k') : throw new \LogicException('No key picked.');
            $where = 'Execution '.$number;

            $steps += Replay::trace($where, $trace, [
                'createSecret' => static fn (): null => $registry->createSecret($state, 'the secret', $clock->now()->modify('+1 day')),
                'bootstrap' => static function (ItfState $step) use ($registry, $state, $clock, $adding, $key): void {
                    self::assertTrue($registry->secretWorks($state, 'the secret'));
                    $intent = $adding($key($step));
                    $new = $registry->newKey($state, $intent);
                    $first = [] === $state->keys;
                    $registry->bootstrap($state, new VerifiedIntent($intent, $new, $clock->now()));

                    // The first key takes effect at once: Verifier applies its change, due now, as soon as
                    // it's asked for. With no keys, there's no other change to apply.
                    if ($first) {
                        self::assertCount(1, $registry->applyDue($state, $clock->now()));
                    }
                },
                'addKey' => static function (ItfState $step) use ($registry, $state, $adding, $key, $signed): void {
                    $intent = $adding($key($step));
                    $registry->add($state, $registry->newKey($state, $intent), $signed(OperationPolicy::ADD_KEY, $intent->parameters));
                },
                'removeKey' => static fn (ItfState $step): KeyChange => $registry->remove($state, $signed(OperationPolicy::REMOVE_KEY, ['credential_id' => $keys[$key($step)]->id()])),
                'cancel' => static function (ItfState $step) use ($registry, $state, $signed, $keys): void {
                    $change = $step->pick('c');
                    \assert(\is_array($change) && \is_string($change['key']) && $change['kind'] instanceof ItfVariant);
                    $kind = 'Add' === $change['kind']->tag ? KeyChange::ADD : KeyChange::REMOVE;
                    $id = array_key_first(array_filter($state->keyChanges, static fn (KeyChange $one): bool => $one->kind === $kind && $one->key->id() === $keys[$change['key']]->id()));
                    $registry->cancel($state, $signed(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => (string) $id]));
                },
                'apply' => static function () use ($registry, $state, $clock): void {
                    self::assertNotSame([], $registry->applyDue($state, $clock->now()), 'The model applied a change.');
                },
                'tick' => static fn (): null => $clock->sleep(self::DELAY),
                'nothingHappens' => static fn (): null => null,
            ], static function (ItfState $step): array {
                $ring = $step->var('ring');
                $changes = $step->var('changes');
                \assert(\is_array($ring) && \is_array($changes));
                $pending = array_map(self::change(...), $changes);
                sort($ring);
                sort($pending);

                return ['ticks' => $step->var('now'), 'ring' => $ring, 'changes' => $pending, 'secret' => $step->var('secret')];
            }, static function () use ($state, $clock, $start, $name, $registry): array {
                $ring = array_map($name, array_keys($state->keys));
                $pending = array_values(array_map(static fn (KeyChange $change): string => \sprintf('%s %s at %d', $change->kind, $name($change->key->id()), intdiv($change->effectiveAt->getTimestamp() - $start, self::DELAY)), $state->keyChanges));
                sort($ring);
                sort($pending);

                return ['ticks' => intdiv($clock->now()->getTimestamp() - $start, self::DELAY), 'ring' => $ring, 'changes' => $pending, 'secret' => $registry->secretWorks($state, 'the secret')];
            });

            // What the model can't do at the end, the registry refuses: adding a key it has or is getting,
            // removing one it hasn't, one already leaving, or the last.
            $last = end($trace);
            \assert($last instanceof ItfState);
            self::assertRefusals($where, $last, $registry, $state, $keys, $adding, $signed);
        }

        self::assertGreaterThan(500, $steps, 'Too few steps were replayed to mean much.');
    }

    /**
     * @param array<string, Key>                                     $keys
     * @param \Closure(string): Intent                               $adding
     * @param \Closure(string, array<string, mixed>): VerifiedIntent $signed
     */
    private static function assertRefusals(string $where, ItfState $last, KeyRegistry $registry, VerifierState $state, array $keys, \Closure $adding, \Closure $signed): void
    {
        $ring = $last->var('ring');
        $changes = $last->var('changes');
        \assert(\is_array($ring) && \is_array($changes));
        $described = array_map(self::change(...), $changes);
        $leaving = array_map(static fn (string $change): string => explode(' ', $change)[1], array_values(array_filter($described, static fn (string $change): bool => str_starts_with($change, 'remove '))));
        $coming = array_map(static fn (string $change): string => explode(' ', $change)[1], array_values(array_filter($described, static fn (string $change): bool => str_starts_with($change, 'add '))));

        foreach ($keys as $name => $key) {
            $copy = clone $state;

            if (\in_array($name, $ring, true) || \in_array($name, $coming, true)) {
                self::assertRefused($where.': adding '.$name.' again', Refusal::InvalidKey, static fn (): Key => $registry->newKey($copy, $adding($name)));
            }

            $removable = \in_array($name, $ring, true) && !\in_array($name, $leaving, true) && \count($ring) - \count($leaving) > 1;

            if ([] !== $ring && !$removable) {
                self::assertRefused($where.': removing '.$name, \in_array($name, $ring, true) && !\in_array($name, $leaving, true) ? Refusal::LastKey : Refusal::UnknownKey, static fn (): KeyChange => $registry->remove($copy, $signed(OperationPolicy::REMOVE_KEY, ['credential_id' => $key->id()])));
            }
        }
    }

    /**
     * A change of the model's, as "add k1 at 3".
     */
    private static function change(mixed $change): string
    {
        \assert(\is_array($change) && $change['kind'] instanceof ItfVariant && \is_string($change['key']) && \is_int($change['effectiveAt']));

        return \sprintf('%s %s at %d', 'Add' === $change['kind']->tag ? 'add' : 'remove', $change['key'], $change['effectiveAt']);
    }

    private static function assertRefused(string $what, Refusal $refusal, \Closure $attempt): void
    {
        try {
            $attempt();
            self::fail($what.' was let through: the model can\'t.');
        } catch (IntentRefused $refused) {
            self::assertSame($refusal, $refused->refusal, $what);
        }
    }

    /**
     * @param array{id: string, publicKey: string, userHandle: string} $passkey
     *
     * @return array<string, string>
     */
    private static function parameters(array $passkey, string $label): array
    {
        return ['credential_id' => Base64Url::encode($passkey['id']), 'public_key' => Base64Url::encode($passkey['publicKey']), 'user_handle' => Base64Url::encode($passkey['userHandle']), 'label' => $label];
    }
}
