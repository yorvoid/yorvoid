<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Tests\Support\Permits;

/**
 * What every signed-in request does first: checking its session's token (Sessions::check()), on
 * PostgreSQL, the session found, active and touched.
 */
final class SessionBench
{
    /** @var array{Sessions, Secret}|null */
    private static ?array $session = null;

    private Sessions $sessions;

    private Secret $token;

    public function setUp(): void
    {
        [$this->sessions, $this->token] = self::$session ??= self::start();
    }

    public function benchSessionCheck(): void
    {
        $this->sessions->check(Realm::default(), $this->token) ?? throw new \LogicException('No session.');
    }

    /**
     * @return array{Sessions, Secret}
     */
    private static function start(): array
    {
        $stack = Postgres::stack('session');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $sessions = new Sessions($stack->sessions, $principals, $log, $stack->transactions);
        $principal = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Console));
        $started = Permits::call($sessions->start(...), new StartSession(Realm::default(), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web));

        return [$sessions, $started->token];
    }
}
