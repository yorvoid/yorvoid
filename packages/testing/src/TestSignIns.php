<?php

declare(strict_types=1);

namespace YorvoID\Testing;

use Psr\Clock\ClockInterface;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;

/**
 * Runs SignInForTest: starts the session, as a consequence, without checking anything. Only Scenario's
 * own Operations has it.
 *
 * @internal
 */
final readonly class TestSignIns implements OperationHandler
{
    public function __construct(
        private Sessions $sessions,
        private ClockInterface $clock,
    ) {
    }

    public function operations(): array
    {
        return [SignInForTest::class => $this->signIn(...)];
    }

    public function signIn(SignInForTest $command, Permit $permit, Origin $origin): StartedSession
    {
        $permit->use($command);
        $passkey = AuthenticationMethod::Passkey === $command->method;

        return $permit->cause($this->sessions->start(...), new StartSession($command->realm, $command->principalId, new AuthenticationContext(new AuthenticationFactor($command->method, $this->clock->now(), $passkey, $passkey))), $origin, new ActingPrincipal($command->realm, $command->principalId));
    }
}
