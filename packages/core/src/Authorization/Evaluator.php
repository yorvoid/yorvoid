<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Authorization\BuiltIn\AbilityPermit;
use YorvoID\Core\Authorization\BuiltIn\AuthenticationPermit;
use YorvoID\Core\Authorization\BuiltIn\OperatorPermit;
use YorvoID\Core\Authorization\BuiltIn\SelfServicePermit;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NoSelfService;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\SelfServicePolicy;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StoreUnreachable;

/**
 * Decides with the policy's elements and the actor's authority, by the precedence of 06 §3, the first
 * row that applies:
 *
 *   1. a forbid holds                → Deny
 *   2. a forbid is unknown           → Indeterminate (a forbid can't be ruled out)
 *   3. the authority is invalid      → Deny
 *   4. the authority is unknown      → Indeterminate
 *   5. a permit holds                → Permit
 *   6. a permit is unknown           → Indeterminate
 *   7. otherwise                     → Deny, with the remedies of the permits that failed for want of evidence
 *
 * Every element that matches is evaluated, so the outcome and its reasons never depend on their order
 * (P2). An element or check that throws counts as unknown, never as holding (P1).
 *
 * @api
 */
final readonly class Evaluator implements Authorization
{
    /** @var array<string, PolicyElement> By name */
    private array $elements;

    /** @var list<VersionSource> */
    private array $versions;

    /**
     * @param iterable<PolicyElement> $elements  The policy: permits and forbids
     * @param OfflineDecider|null     $offline   Asked while a store is unreachable, for the operations that accept it
     * @param iterable<VersionSource> $versions  What each decision records the version of (AUZ-009)
     * @param IdGenerator             $ids       Each decision's ID
     * @param DecisionLog|null        $decisions Where decisions are recorded (AUZ-040); without it, nowhere
     *
     * @throws IntegratorError When an element is neither a permit nor a forbid, two share a name, or a
     *                         permit authorizes by Consequence, which only another operation's permit gives
     */
    public function __construct(
        iterable $elements,
        private AuthorityCheck $authority,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        private ?OfflineDecider $offline = null,
        iterable $versions = [],
        private IdGenerator $ids = new UuidV7Generator(),
        private ?DecisionLog $decisions = null,
    ) {
        $sources = [];

        foreach ($versions as $source) {
            $sources[] = $source;
        }

        $this->versions = $sources;
        $list = [];

        foreach ($elements as $element) {
            if ($element instanceof PermitElement === $element instanceof ForbidElement) {
                throw new IntegratorError(\sprintf('The policy element "%s" must be a permit or a forbid.', $element->name()), 'Implement PermitElement or ForbidElement, not both and not only PolicyElement.', 'concepts/authorization.md#policy-elements');
            }

            if ($element instanceof PermitElement && AuthorizedBy::Consequence === $element->basis()) {
                throw new IntegratorError(\sprintf('The permit "%s" authorizes by Consequence.', $element->name()), 'Only an operation\'s permit gives its consequences: authorize by Ability, Self, Operator or Authentication.', 'concepts/authorization.md#policy-elements');
            }

            if (isset($list[$element->name()])) {
                throw new IntegratorError(\sprintf('Two policy elements are named "%s".', $element->name()), 'Give each element a name of its own: reasons and the log name it.', 'concepts/authorization.md#policy-elements');
            }

            $list[$element->name()] = $element;
        }

        // By name: the decision's reasons, and the detail it leads with, never depend on the order given.
        ksort($list, \SORT_STRING);
        $this->elements = $list;
    }

    /**
     * YorvoID's own ways to authorize (#[Operation]'s AuthorizedBy) as permits, and the elements given.
     *
     * @param AuthorityCheck          $authority   Usually PrincipalAuthority, with the principals' store
     * @param AbilityPolicy|null      $abilities   Who holds an ability; without one, no ability permits anything
     * @param iterable<PolicyElement> $elements    The application's own permits and forbids
     * @param bool|null               $commandLine Whether PHP runs from the command line, as it does for console
     *                                             commands and workers: the host's operator acts only from there.
     *                                             Found out if not given
     * @param OfflineDecider|null     $offline     Asked while a store is unreachable, for the operations that accept it
     * @param iterable<VersionSource> $versions    What each decision records the version of
     * @param DecisionLog|null        $decisions   Where decisions are recorded
     */
    public static function builtIn(
        AuthorityCheck $authority,
        ?AbilityPolicy $abilities = null,
        SelfServicePolicy $selfService = new NoSelfService(),
        iterable $elements = [],
        ?bool $commandLine = null,
        ClockInterface $clock = new NativeClock(),
        ?LoggerInterface $logger = null,
        ?OfflineDecider $offline = null,
        iterable $versions = [],
        IdGenerator $ids = new UuidV7Generator(),
        ?DecisionLog $decisions = null,
    ): self {
        $builtIn = [
            new AuthenticationPermit(),
            new OperatorPermit($commandLine ?? \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)),
            new SelfServicePermit($selfService),
        ];

        if (null !== $abilities) {
            $builtIn[] = new AbilityPermit($abilities);
        }

        return new self([...$builtIn, ...$elements], $authority, $clock, $logger ?? new NullLogger(), $offline, $versions, $ids, $decisions);
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return $this->evaluate(Request::forOperation($operation, $command, $actor, $origin, $this->clock->now()));
    }

    /**
     * Whether the actor holds an ability, on a resource or in the whole realm, for what isn't one of
     * YorvoID's operations, e.g. an application's "edit this page". Only permits that authorize by an
     * ability speak for it; the precedence and the reasons are the operations'.
     */
    public function decideAbility(Actor $actor, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin): Decision
    {
        return $this->evaluate(Request::forAbility($ability, $realm, $resource, $actor, $origin, $this->clock->now()));
    }

    /**
     * Decides, identifies the decision and records it: an operation's before it runs.
     */
    private function evaluate(Request $request): Decision
    {
        $decision = $this->judge($request)->identified((string) $this->ids->next(), $request->at, $this->versionsOf($request));

        return null === $this->decisions ? $decision : $this->decisions->record($request, $decision);
    }

    /**
     * The versions of what the decision read, as they are now: a source that can't tell is left out,
     * and the decision itself comes from the elements, which report what they couldn't read.
     *
     * @return array<string, int>
     */
    private function versionsOf(Request $request): array
    {
        $versions = [];

        foreach ($this->versions as $source) {
            try {
                $versions[$source->name()] = $source->version($request->realm);
            } catch (\Throwable $e) {
                $this->logger->warning('YorvoID couldn\'t read the {source} version: {message}', ['source' => $source->name(), 'message' => $e->getMessage()]);
            }
        }

        return $versions;
    }

    private function judge(Request $request): Decision
    {
        $authorizedBy = $request->authorizedBy();
        $reasons = [];
        /** @var array<string, list<Truth>> $forbids */
        $forbids = ['holds' => [], 'unknown' => []];
        /** @var array<string, list<array{PermitElement, Truth}>> $permits */
        $permits = ['holds' => [], 'unknown' => [], 'fails' => []];

        foreach ($this->elements as $element) {
            if ($element instanceof PermitElement && !\in_array($element->basis(), $authorizedBy, true)) {
                continue;
            }

            $truth = self::safely(static fn (): ?Truth => $element->matches($request) ? null : Truth::fails('Not about this request.'));

            if (null === $truth) {
                $truth = self::safely(static fn (): Truth => $element->evaluate($request));
            } elseif (TruthValue::Fails === $truth->value) {
                continue;
            }

            $kind = match ($truth->value) {
                TruthValue::Holds => 'holds',
                TruthValue::Unknown => 'unknown',
                TruthValue::Fails => 'fails',
            };

            if ($element instanceof PermitElement) {
                $permits[$kind][] = [$element, $truth];
                $reasons[] = new Reason($element->name(), 'permit', $truth);
            } else {
                if ('fails' !== $kind) {
                    $forbids[$kind][] = $truth;
                }

                $reasons[] = new Reason($element->name(), 'forbid', $truth);
            }
        }

        $authority = self::safely(fn (): Truth => $this->authority->check($request));
        $reasons[] = new Reason('authority', 'authority', $authority);
        $decision = $this->precedence($request, $forbids, $authority, $permits, $reasons);

        if ([] === $decision->indeterminate) {
            return $decision;
        }

        $this->logger->warning('YorvoID couldn\'t decide whether to run "{operation}": {reason}', ['operation' => $request->subject(), 'reason' => $decision->reason, 'indeterminate' => array_map(static fn (IndeterminateReason $reason): string => $reason->value, $decision->indeterminate)]);
        $offline = $this->offline($request, $decision, $permits);

        if (null === $offline) {
            return $decision;
        }

        $this->logger->warning('YorvoID permitted "{operation}" offline: {reason}', ['operation' => $request->subject(), 'reason' => $offline->reason]);

        return $offline;
    }

    /**
     * Has every element that checks the operations check them: Operations asks when it's built.
     *
     * @throws IntegratorError
     */
    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        foreach ($this->elements as $element) {
            if ($element instanceof ChecksCatalogue) {
                $element->checkCatalogue($catalogue);
            }
        }
    }

    /**
     * Whether an OfflineDecider is registered: Operations refuses to be built when that and the
     * operations that accept Permit-offline don't go together.
     */
    public function decidesOffline(): bool
    {
        return null !== $this->offline;
    }

    /**
     * Permit-offline (P1′): only for an Indeterminate whose every reason is store_unreachable, an
     * operation that accepts it, a permit that could have applied, and the decider holding.
     *
     * @param array<string, list<array{PermitElement, Truth}>> $permits
     */
    private function offline(Request $request, Decision $decision, array $permits): ?Decision
    {
        if (null === $this->offline || true !== $request->operation?->acceptsOffline || [IndeterminateReason::StoreUnreachable] !== $decision->indeterminate) {
            return null;
        }

        $basis = null;

        foreach ($request->authorizedBy() as $by) {
            foreach ([...$permits['holds'], ...$permits['unknown']] as [$element]) {
                if ($element->basis() === $by) {
                    $basis ??= $by;
                }
            }
        }

        if (null === $basis) {
            return null;
        }

        $truth = self::safely(fn (): Truth => $this->offline->decide($request));

        if (TruthValue::Holds !== $truth->value) {
            return null;
        }

        return new Decision(Verdict::PermitOffline, $basis, \sprintf('Permitted offline: %s', $truth->detail), [...$decision->reasons, new Reason('offline', 'offline', $truth)]);
    }

    /**
     * @param array<string, list<Truth>>                       $forbids
     * @param array<string, list<array{PermitElement, Truth}>> $permits
     * @param list<Reason>                                     $reasons
     */
    private function precedence(Request $request, array $forbids, Truth $authority, array $permits, array $reasons): Decision
    {
        // 1.
        if ([] !== $forbids['holds']) {
            return Decision::deny(\sprintf('A forbid applies: %s', $forbids['holds'][0]->detail), $reasons);
        }

        $couldPermit = [] !== $permits['holds'] || [] !== $permits['unknown'];

        // 2. Reported even when nothing could permit it (Q-09): the unknown is an operational signal.
        if ([] !== $forbids['unknown']) {
            return Decision::indeterminate(
                self::why([IndeterminateReason::UnknownForbid], $forbids['unknown']),
                \sprintf('A forbid couldn\'t be ruled out: %s%s', $forbids['unknown'][0]->detail, $couldPermit && TruthValue::Fails !== $authority->value ? '' : ' (refused regardless: nothing could permit it)'),
                $reasons,
            );
        }

        // 3. and 4.
        if (TruthValue::Fails === $authority->value) {
            return Decision::deny(\sprintf('The actor\'s authority is invalid: %s', $authority->detail), $reasons);
        }

        if (TruthValue::Unknown === $authority->value) {
            return Decision::indeterminate(self::why([], [$authority]), \sprintf('The actor\'s authority couldn\'t be established: %s', $authority->detail), $reasons);
        }

        // 5. The basis the operation names first, as #[Operation] orders them.
        if ([] !== $permits['holds']) {
            $chosen = null;

            foreach ($request->authorizedBy() as $basis) {
                foreach ($permits['holds'] as [$element, $truth]) {
                    if ($element->basis() === $basis) {
                        $chosen ??= [$element, $truth];
                    }
                }
            }

            \assert(null !== $chosen);

            return Decision::permit($chosen[0]->basis(), $chosen[1]->detail, $reasons);
        }

        // 6.
        if ([] !== $permits['unknown']) {
            return Decision::indeterminate(
                self::why([], array_map(static fn (array $permit): Truth => $permit[1], $permits['unknown'])),
                \sprintf('A permit couldn\'t be evaluated: %s', $permits['unknown'][0][1]->detail),
                $reasons,
            );
        }

        // 7. Each remedy once, however many permits ask for it.
        $remedies = [];

        foreach ($permits['fails'] as [, $truth]) {
            foreach ($truth->remedies as $remedy) {
                $remedies[json_encode([$remedy->name(), $remedy->parameters()], \JSON_THROW_ON_ERROR)] ??= $remedy;
            }
        }

        return Decision::deny(
            [] === $permits['fails'] ? \sprintf('No permit applies to "%s".', $request->subject()) : $permits['fails'][0][1]->detail,
            $reasons,
            array_values($remedies),
        );
    }

    /**
     * @param list<IndeterminateReason> $first
     * @param list<Truth>               $unknowns
     *
     * @return non-empty-list<IndeterminateReason>
     */
    private static function why(array $first, array $unknowns): array
    {
        $why = $first;

        foreach ($unknowns as $truth) {
            $reason = $truth->reason ?? IndeterminateReason::EvaluationError;

            if (!\in_array($reason, $why, true)) {
                $why[] = $reason;
            }
        }

        \assert([] !== $why);

        return $why;
    }

    /**
     * @template T of Truth|null
     *
     * @param \Closure(): T $work
     *
     * @return T|Truth
     */
    private static function safely(\Closure $work): ?Truth
    {
        try {
            return $work();
        } catch (IntegratorError $error) {
            // A mistake in the setup: shown, not decided around. Nothing runs either way.
            throw $error;
        } catch (MissingData $missing) {
            return Truth::unknown(IndeterminateReason::MissingData, $missing->getMessage());
        } catch (\Throwable $exception) {
            $unreachable = StoreUnreachable::of($exception);

            return null !== $unreachable
                ? Truth::unknown(IndeterminateReason::StoreUnreachable, $unreachable->getMessage())
                : Truth::unknown(IndeterminateReason::EvaluationError, \sprintf('%s: %s', $exception::class, $exception->getMessage()));
        }
    }
}
