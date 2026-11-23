<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Duration;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Key\KeyFiles;

/**
 * The signing keys: the realm's key file exists, only its owner can read it, and it holds a valid ring.
 * Without one there's nothing to check: nothing YorvoID does yet needs it, until signed checkpoints
 * (AUD-003). With keys per realm, a realm without a file of its own is a problem.
 *
 * And its MAC key, for the attributes decisions read (M-9): without one they're withheld; an active one
 * older than a year is due a rotation; a retired one kept longer than the decision log keeps records
 * confirms nothing any more, and can be pruned.
 *
 * @internal
 */
final readonly class KeysCheck implements Check
{
    public function __construct(
        private KeyFiles $keys,
        private Realm $realm,
        private ClockInterface $clock = new NativeClock(),
        private string $keepDecisions = '90d',
    ) {
    }

    public function findings(): iterable
    {
        $file = $this->keys->path($this->realm);

        if (null === $file) {
            return;
        }

        if (!file_exists($file)) {
            yield $this->keys->isPerRealm()
                ? Finding::problem(\sprintf('The realm "%s" has no keys: "%s" doesn\'t exist.', $this->realm->name, $file), \sprintf('Create them with yorvoid:keys:rotate --realm %s.', $this->realm->name), 'concepts/keys.md#a-ring-for-each-realm')
                : Finding::problem(\sprintf('The key file "%s" doesn\'t exist.', $file), 'Create it with yorvoid:keys:rotate.', 'concepts/keys.md#rotating-keys');

            return;
        }

        // Throws what's wrong with it, which the doctor reports.
        $ring = KeyRing::fromFile($file);
        $now = $this->clock->now();
        $realm = $this->keys->isPerRealm() ? ' --realm '.$this->realm->name : '';
        $active = $ring->mac();

        if (null === $active) {
            yield Finding::warning(\sprintf('The key ring of "%s" has no MAC key: the attributes decisions read that aren\'t public are withheld from their records.', $this->realm->name), \sprintf('Add one with yorvoid:keys:rotate --mac%s, unless only the FIPS module\'s own functions may be used.', $realm), 'concepts/keys.md#mac-keys');
        } elseif (null !== $active->created && $active->created < $now->modify('-1 year')) {
            yield Finding::warning(\sprintf('The MAC key %s of "%s" has been active for more than a year.', $active->id, $this->realm->name), \sprintf('Rotate it: yorvoid:keys:rotate --mac%s.', $realm), 'concepts/keys.md#mac-keys');
        }

        $keep = Duration::parse($this->keepDecisions);

        foreach ($ring->macKeys() as $key) {
            if (null !== $key->since && $keep->after($key->since) < $now) {
                yield Finding::warning(\sprintf('The MAC key %s of "%s" was retired longer ago than the decision log keeps records (%s): it confirms nothing any more.', $key->id, $this->realm->name, $keep), \sprintf('Remove it: yorvoid:keys:rotate --mac --prune%s.', $realm), 'concepts/keys.md#mac-keys');
            }
        }
    }
}
