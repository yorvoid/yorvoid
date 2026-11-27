<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\TestCase;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Operation\Fixtures\Consequences\Twice;
use YorvoID\Tests\Core\Operation\Fixtures\Consequences\Unbound;
use YorvoID\Tests\Support\Permits;

/**
 * M2-13 (L-2): a consequence is about its operation's principal only, which the operation says before
 * it causes anything about anyone, and runs as many times as the operation declares it.
 */
final class PermitTest extends TestCase
{
    private Realm $realm;

    private PrincipalId $ana;

    private PrincipalId $bob;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->ana = new PrincipalId(Ids::next());
        $this->bob = new PrincipalId(Ids::next());
    }

    public function testAPermitIsAboutItsCommandsPrincipal(): void
    {
        $permit = $this->used(new Twice($this->realm, $this->ana));

        Permits::consequence($permit, $this->token($this->ana));
        $this->refused(fn () => Permits::consequence($permit, $this->token($this->bob)), 'A consequence of "acme.test.twice" is about another principal.');
        $this->refused(fn () => $permit->about($this->bob), 'The permit for "acme.test.twice" is about another principal already.');
        $permit->about($this->ana);
        Permits::consequence($permit, $this->token($this->ana));
    }

    /**
     * C1: nobody's consequence picks whom the operation is about.
     */
    public function testAPermitAboutNobodyYetCausesNothingAboutAnyone(): void
    {
        $permit = $this->used(new Unbound($this->realm));

        $this->refused(fn () => Permits::consequence($permit, $this->token($this->bob)), 'A consequence of "acme.test.unbound" is about a principal, but "acme.test.unbound" isn\'t about a principal yet.');
        // What isn't about a principal runs: creating one.
        Permits::consequence($permit, new CreatePrincipal($this->realm, PrincipalKind::Human, PrincipalStatus::Active));

        $permit->about($this->ana);
        $this->refused(fn () => Permits::consequence($permit, $this->token($this->bob)), 'A consequence of "acme.test.unbound" is about another principal.');
        self::assertSame('yorvoid.token.issue', Permits::consequence($permit, $this->token($this->ana))->operation);
    }

    public function testEachConsequenceRunsAsManyTimesAsItsDeclared(): void
    {
        $twice = $this->used(new Twice($this->realm, $this->ana));
        Permits::consequence($twice, $this->token($this->ana));
        Permits::consequence($twice, $this->token($this->ana));
        $this->refused(fn () => Permits::consequence($twice, $this->token($this->ana)), 'This permit for "acme.test.twice" ran its consequence "yorvoid.token.issue" already: as many times as it says it causes it.');
        Permits::consequence($twice, new EndSessions($this->realm, $this->ana, SessionEndReason::Reported));
        $this->refused(fn () => Permits::consequence($twice, new EndSessions($this->realm, $this->ana, SessionEndReason::Reported)), 'This permit for "acme.test.twice" ran its consequence "yorvoid.session.end_all" already: as many times as it says it causes it.');

        $once = $this->used(new Unbound($this->realm));
        $once->about($this->ana);
        Permits::consequence($once, $this->token($this->ana));
        $this->refused(fn () => Permits::consequence($once, $this->token($this->ana)), 'This permit for "acme.test.unbound" ran its consequence "yorvoid.token.issue" already: as many times as it says it causes it.');
    }

    /**
     * A refused consequence uses nothing up.
     */
    public function testARefusedConsequenceIsntCounted(): void
    {
        $permit = $this->used(new Unbound($this->realm));
        $this->refused(fn () => Permits::consequence($permit, $this->token($this->ana)), 'A consequence of "acme.test.unbound" is about a principal, but "acme.test.unbound" isn\'t about a principal yet.');
        $permit->about($this->ana);

        self::assertSame('yorvoid.token.issue', Permits::consequence($permit, $this->token($this->ana))->operation);
    }

    /**
     * A consequence is about its own command's principal, for what it causes in turn.
     */
    public function testAConsequenceIsAboutItsOwnPrincipal(): void
    {
        $parent = $this->used(new Twice($this->realm, $this->ana));
        $token = $this->token($this->ana);
        $child = Permits::consequence($parent, $token);
        $child->use($token);

        $this->refused(fn () => $child->about($this->bob), 'The permit for "yorvoid.token.issue" is about another principal already.');
    }

    /**
     * @param Command<mixed> $command
     */
    private function used(Command $command): Permit
    {
        $permit = Permits::for($command);
        $permit->use($command);

        return $permit;
    }

    private function token(PrincipalId $principal): IssueToken
    {
        return new IssueToken($this->realm, $principal, 'acme.password_reset', Duration::hours(1));
    }

    /**
     * What an operation created is named once: its OperationRan names one thing.
     */
    public function testWhatItCreatedIsNamedOnce(): void
    {
        $permit = $this->used(new Unbound($this->realm));
        $permit->created(new ResourceRef('role', 'editor'));

        $this->refused(static fn () => $permit->created(new ResourceRef('role', 'writer')), 'The permit for "acme.test.unbound" says what it created already.');
    }

    private function refused(\Closure $run, string $message): void
    {
        try {
            $run();
            self::fail('Not refused: '.$message);
        } catch (IntegratorError $error) {
            self::assertSame($message, strtok($error->getMessage(), "\n"));
        }
    }
}
