<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\RegisterPasskey;
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
    /** Scenario's own, only for signing in: the application's never runs SignInForTest */
    private Operations $signIns;

    /**
     * @param Operations $operations The application's: what Scenario sets up runs through it, as the
     *                               host's operator or the principal's own session
     */
    public function __construct(
        private Operations $operations,
        Sessions $sessions,
        SecurityLog $log,
        private Passkeys $passkeys,
        private PasskeyPolicy $passkeyPolicy,
        private ClockInterface $clock,
        private Realm $realm = new Realm('default'),
    ) {
        $signIns = new TestSignIns($sessions, $clock);
        // It runs only the test sign-in, as someone not signed in: no principal's authority to check.
        $noneClaimed = new class implements AuthorityCheck {
            public function check(Request $request): Truth
            {
                return null === $request->actor->principalId ? Truth::holds('No principal acts.') : Truth::fails('Scenario signs in only.');
            }
        };
        $this->signIns = new Operations(OperationCatalogue::of([$signIns]), Evaluator::builtIn($noneClaimed), $log, [$signIns]);
    }

    /**
     * From a container with YorvoID's services, e.g. Symfony's test container
     * (static::getContainer() in a KernelTestCase).
     */
    public static function of(ContainerInterface $container): self
    {
        return new self(
            self::service($container, Operations::class),
            self::service($container, Sessions::class),
            self::service($container, SecurityLog::class),
            self::service($container, Passkeys::class),
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
        return $this->operations->run(new CreatePrincipal($this->realm, PrincipalKind::Human, email: $email ?? \sprintf('%s@example.com', bin2hex(random_bytes(6))), emailVerified: true), new Operator(), new Origin(OriginKind::Console, 'test'));
    }

    /**
     * A session, as signing in with that method starts it: a passkey, by default, is multi-factor and
     * phishing-resistant.
     */
    public function session(Principal $principal, AuthenticationMethod $method = AuthenticationMethod::Passkey): StartedSession
    {
        return $this->signIns->run(new SignInForTest($this->realm, $principal->id, $method), new Anonymous(), new Origin(OriginKind::Web, 'test'));
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
        $this->operations->run(new RegisterPasskey($this->realm, $ceremony->id, $authenticator->register($ceremony->options), $label), $session, new Origin(OriginKind::Web, 'test'));

        return $authenticator;
    }

    /**
     * A fresh set of recovery codes for the principal.
     *
     * @return list<Secret>
     */
    public function recoveryCodes(Principal $principal): array
    {
        $session = $this->session($principal)->session;

        return $this->operations->run(new GenerateRecoveryCodes($this->realm), $session, new Origin(OriginKind::Web, 'test'));
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
