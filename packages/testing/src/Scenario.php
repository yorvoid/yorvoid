<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * Fixtures for an application's tests (PLT-084), made through YorvoID's own services as people would
 * make them: principals, sessions, passkeys registered by a virtual authenticator, recovery codes. What
 * a test sets up this way is what the application sees in production, with nothing faked but the
 * authenticator and, if the application wants, the clock.
 *
 * @api
 */
final readonly class Scenario
{
    public function __construct(
        private Principals $principals,
        private Sessions $sessions,
        private Passkeys $passkeys,
        private RecoveryCodes $recoveryCodes,
        private PasskeyPolicy $passkeyPolicy,
        private ClockInterface $clock,
        private Realm $realm = new Realm('default'),
    ) {
    }

    /**
     * From a container with YorvoID's services, e.g. Symfony's test container
     * (static::getContainer() in a KernelTestCase).
     */
    public static function of(ContainerInterface $container): self
    {
        return new self(
            self::service($container, Principals::class),
            self::service($container, Sessions::class),
            self::service($container, Passkeys::class),
            self::service($container, RecoveryCodes::class),
            self::service($container, PasskeyPolicy::class),
            self::service($container, ClockInterface::class),
            self::service($container, Realm::class),
        );
    }

    /**
     * A person, with a verified email address: the given one, or a new one each time.
     */
    public function principal(?string $email = null): Principal
    {
        return $this->principals->create(new CreatePrincipal($this->realm, PrincipalKind::Human, email: $email ?? \sprintf('%s@example.com', bin2hex(random_bytes(6))), emailVerified: true), new Origin(OriginKind::Console, 'test'));
    }

    /**
     * A session, as signing in with that method starts it: a passkey, by default, is multi-factor and
     * phishing-resistant.
     */
    public function session(Principal $principal, AuthenticationMethod $method = AuthenticationMethod::Passkey): StartedSession
    {
        $passkey = AuthenticationMethod::Passkey === $method;

        return $this->sessions->start(new StartSession($this->realm, $principal->id, new AuthenticationContext(new AuthenticationFactor($method, $this->clock->now(), $passkey, $passkey))), new Origin(OriginKind::Web, 'test'));
    }

    /**
     * Registers a passkey for the principal, through the registration ceremony; the authenticator
     * that holds it signs in with it later.
     */
    public function passkey(Principal $principal, ?VirtualAuthenticator $authenticator = null, string $label = 'Passkey'): VirtualAuthenticator
    {
        $authenticator ??= new VirtualAuthenticator($this->passkeyPolicy->origins[0]);
        $session = $this->session($principal)->session;
        $ceremony = $this->passkeys->beginRegistration($session);
        $this->passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), $label, new Origin(OriginKind::Web, 'test'));

        return $authenticator;
    }

    /**
     * A fresh set of recovery codes for the principal.
     *
     * @return list<Secret>
     */
    public function recoveryCodes(Principal $principal): array
    {
        return $this->recoveryCodes->generate(new GenerateRecoveryCodes($this->realm), $this->session($principal)->session, new Origin(OriginKind::Web, 'test'));
    }

    /**
     * The clock YorvoID tells the time by, to move it: $scenario->clock()->modify('+2 hours').
     */
    public function clock(): MockClock
    {
        return $this->clock instanceof MockClock ? $this->clock : throw new IntegratorError(\sprintf('YorvoID\'s clock is a %s, which tests can\'t move.', $this->clock::class), 'In the test environment, make the clock service Symfony\'s MockClock (Symfony\Component\Clock\MockClock), or give YorvoID one.', 'concepts/testing.md#the-clock');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
