<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;

/**
 * Decisions of any verdict for tests of what reads them: only the Evaluator makes one that permits
 * otherwise (D-50), and this stays in the repository's tests, never in a package.
 */
final class Decisions
{
    /**
     * As Decision's own constructor takes them.
     *
     * @param list<Reason>              $reasons
     * @param list<Remedy>              $remedies
     * @param list<IndeterminateReason> $indeterminate
     * @param array<string, int>        $versions
     */
    public static function of(Verdict $verdict, ?AuthorizedBy $basis, string $reason, array $reasons = [], array $remedies = [], array $indeterminate = [], ?string $id = null, ?\DateTimeImmutable $at = null, array $versions = [], ?string $hash = null, ?Precedence $precedence = null, ?Decision $viewer = null): Decision
    {
        $make = \Closure::bind(static fn (): Decision => new Decision($verdict, $basis, $reason, $reasons, $remedies, $indeterminate, $id, $at, $versions, $hash, $precedence, $viewer), null, Decision::class);

        return $make();
    }

    /**
     * @param list<Reason> $reasons
     */
    public static function permit(AuthorizedBy $basis, string $reason, array $reasons = [], ?Precedence $precedence = null): Decision
    {
        return self::of(Verdict::Permit, $basis, $reason, $reasons, precedence: $precedence);
    }

    /**
     * The decision for this command and actor, as the Evaluator binds it.
     *
     * @param Command<mixed> $command
     */
    public static function bound(Decision $decision, Command $command, Actor $actor): Decision
    {
        $bind = \Closure::bind(static fn (): Decision => $decision->boundTo($command, $actor), null, Decision::class);

        return $bind();
    }

    /**
     * The decision identified, as the Evaluator does it, a permit too.
     *
     * @param array<string, int> $versions
     */
    public static function identified(Decision $decision, string $id, \DateTimeImmutable $at, array $versions = []): Decision
    {
        $identify = \Closure::bind(static fn (): Decision => $decision->withIdentity($id, $at, $versions), null, Decision::class);

        return $identify();
    }

    /**
     * The decision with its record's hash, as the decision log gives it, a permit's too.
     */
    public static function recorded(Decision $decision, string $hash): Decision
    {
        $record = \Closure::bind(static fn (): Decision => $decision->withHash($hash), null, Decision::class);

        return $record();
    }
}
