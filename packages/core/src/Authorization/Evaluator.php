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
use YorvoID\Core\Authorization\BuiltIn\SelfServiceForOwnAccount;
use YorvoID\Core\Authorization\BuiltIn\SelfServicePermit;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NoSelfService;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\SelfServicePolicy;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StoreUnreachable;

/**
 * Decides with the policy's elements and the actor's authority, by the precedence of 06 §3, the first
 * row that applies:
 *
 *   1. a forbid holds                → Deny, with what would lift it if nothing else would refuse
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
 * Viewing as someone else ({@see ViewingAs}), no operation runs: the forbid "yorvoid.view_as.read_only"
 * holds. An ability is decided for the viewer and the target, each as above, and intersected: a Permit
 * only if both are; else Indeterminate if either is; else a Deny. The decision carries the reasons of the
 * side that decided, the target's when both refuse, and the viewer's own decision.
 *
 * @api
 */
final readonly class Evaluator implements Authorization
{
    /** @var array<string, PolicyElement> By name */
    private array $elements;

    /** @var list<VersionSource> */
    private array $versions;

    /** @var \WeakMap<Request, non-empty-list<Request>> The sides of a batch's view-as requests, as preloaded */
    private \WeakMap $sides;

    /** @var list<ProvesActors> */
    private array $actors;

    /** @var \WeakMap<Request, Truth> Requests by an actor that isn't what it claims, and why */
    private \WeakMap $unproven;

    /** @var \ArrayObject<string, true> The actor classes warned about, once each */
    private \ArrayObject $warned;

    private DecisionScope $scope;

    private AttributeMacs $macs;

    /**
     * @param iterable<PolicyElement> $elements  The policy: permits and forbids
     * @param OfflineDecider|null     $offline   Asked while a store is unreachable, for the operations that accept it
     * @param iterable<VersionSource> $versions  What each decision records the version of (AUZ-009)
     * @param IdGenerator             $ids       Each decision's ID
     * @param DecisionLog|null        $decisions Where decisions are recorded (AUZ-040); without it, nowhere
     * @param iterable<ProvesActors>  $actors    What proves actors beyond core's own, e.g. sessions (StoredSessions, D-45)
     * @param DecisionScope|null      $scope     The one the cached stores read in, so that they read at the versions a
     *                                           decision read before judging (D-44); its own, if not given
     * @param AttributeMacs|null      $macs      How the attributes a decision read are recorded (M-9); without one, those
     *                                           that aren't public are withheld
     *
     * @throws IntegratorError When an element is neither a permit nor a forbid, two share a name, a
     *                         permit authorizes by Consequence, which only another operation's permit gives,
     *                         or an element lacks a forbid it requires (RequiresForbids)
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
        iterable $actors = [],
        ?DecisionScope $scope = null,
        ?AttributeMacs $macs = null,
    ) {
        $this->scope = $scope ?? new DecisionScope();
        $this->macs = $macs ?? new AttributeMacs();
        $provers = [];

        foreach ($actors as $prover) {
            $provers[] = $prover;
        }

        $this->actors = $provers;
        $this->unproven = new \WeakMap();
        /** @var \ArrayObject<string, true> $warned */
        $warned = new \ArrayObject();
        $this->warned = $warned;
        $sources = [];

        foreach ($versions as $source) {
            $sources[] = $source;
        }

        $this->versions = $sources;
        $this->sides = new \WeakMap();
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

        $list = self::withOwnAccount($list);
        // By name: the decision's reasons, and the detail it leads with, never depend on the order given.
        ksort($list, \SORT_STRING);
        $this->elements = $list;

        foreach ($list as $element) {
            if ($element instanceof RequiresForbids) {
                $this->requireForbids(\sprintf('The policy element "%s"', $element->name()), $element);
            }
        }
    }

    /**
     * Every evaluator holds one's own account to the self-service policy, whatever permits the change
     * (M2-13, H-4): with the self-service permit's policy, or none, which refuses, without one.
     *
     * @param array<string, PolicyElement> $list
     *
     * @return array<string, PolicyElement>
     *
     * @throws IntegratorError When it's given with another policy than the permit's
     */
    private static function withOwnAccount(array $list): array
    {
        $permit = $list['yorvoid.self_service'] ?? null;
        $policy = $permit instanceof SelfServicePermit ? $permit->policy : new NoSelfService();
        $forbid = $list[SelfServiceForOwnAccount::NAME] ?? null;

        if (null === $forbid) {
            $list[SelfServiceForOwnAccount::NAME] = new SelfServiceForOwnAccount($policy);
        } elseif (!$forbid instanceof SelfServiceForOwnAccount || ($permit instanceof SelfServicePermit && $forbid->policy !== $permit->policy)) {
            throw new IntegratorError(\sprintf('The policy element "%s" isn\'t the self-service permit\'s own.', SelfServiceForOwnAccount::NAME), 'Leave it out: the evaluator adds it, with the self-service permit\'s policy.', 'concepts/authorization.md#ones-own-account');
        }

        return $list;
    }

    /**
     * That the policy has the forbids something needs, as forbids.
     *
     * @param string $what Who needs them, for the error, e.g. 'The policy element "yorvoid.roles"'
     *
     * @throws IntegratorError When one isn't in the policy, or isn't a forbid
     */
    public function requireForbids(string $what, RequiresForbids $needing): void
    {
        foreach ($needing->requiredForbids() as $name => $how) {
            if (!($this->elements[$name] ?? null) instanceof ForbidElement) {
                throw new IntegratorError(\sprintf('%s needs the forbid "%s", which isn\'t in the policy.', $what, $name), $how, 'concepts/authorization.md#policy-elements');
            }
        }
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
     * @param iterable<ProvesActors>  $actors      What proves actors beyond core's own, e.g. sessions (StoredSessions)
     * @param DecisionScope|null      $scope       The one the cached stores read in
     * @param AttributeMacs|null      $macs        How the attributes a decision read are recorded
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
        iterable $actors = [],
        ?DecisionScope $scope = null,
        ?AttributeMacs $macs = null,
    ): self {
        $builtIn = [
            new AuthenticationPermit(),
            new OperatorPermit($commandLine ?? \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)),
            new SelfServicePermit($selfService),
        ];

        if (null !== $abilities) {
            $builtIn[] = new AbilityPermit($abilities);
        }

        return new self([...$builtIn, ...$elements], $authority, $clock, $logger ?? new NullLogger(), $offline, $versions, $ids, $decisions, $actors, $scope, $macs);
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        // For this command and this actor only: Operations issues on it once (D-50).
        return self::bound($this->evaluate(Request::forOperation($operation, $command, $actor, $origin, $this->clock->now())), $command, $actor);
    }

    /**
     * A consequence of a permitted operation (D-50): the parent's decision holds, so its permits and
     * the authority check aren't asked again, and neither are the forbids, except those that apply to
     * consequences (AppliesToConsequences). The actor is proven again, its versions are read, and it's
     * recorded under the parent.
     *
     * @throws IntegratorError When the parent isn't a Permit this evaluator made
     */
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        $permitted = match ($parent->verdict) {
            Verdict::Permit, Verdict::PermitOffline => null !== $parent->id,
            Verdict::Deny, Verdict::Indeterminate => false,
        };

        if (!$permitted) {
            throw new IntegratorError(\sprintf('"%s" was asked as a consequence of a decision that isn\'t a permit.', $operation->name), 'Only a permitted operation has consequences: run them on its permit (Permit::cause()).', 'concepts/operations-and-events.md#permits');
        }

        $request = Request::forOperation($operation, $command, $actor, $origin, $this->clock->now());
        $request = $this->provenAs($request, $this->proveActor($request->actor));
        $decision = $this->judged($request, fn (): Decision => $this->consequence($request, $parent));

        return self::bound(null === $this->decisions ? $decision : $this->decisions->record($request, $decision, $parent->id), $command, $actor);
    }

    /**
     * Whether the actor holds an ability, on a resource or in the whole realm, for what isn't one of
     * YorvoID's operations, e.g. an application's "edit this page". Only permits that authorize by an
     * ability speak for it; the precedence and the reasons are the operations'.
     *
     * @param object|null $subject The application's own object it's about, e.g. the page, for rules
     *                             and attribute resolvers: the resource is the one it concerns, if not given
     *
     * @throws IntegratorError When the subject concerns another resource than the one given
     */
    public function decideAbility(Actor $actor, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin, ?object $subject = null): Decision
    {
        return $this->evaluate(Request::forAbility($ability, $realm, $resource, $actor, $origin, $this->clock->now(), $subject));
    }

    /**
     * Whether the actor holds an ability on each of these resources, decided as one by one, at one
     * moment: the elements that preload read what the batch needs at once (AUZ-030, AUZ-032).
     *
     * @param list<array{ResourceRef|null, object|null}> $asked Each resource with its subject, as decideAbility() takes them
     *
     * @return list<Decision> One for each, in their order
     *
     * @throws IntegratorError When a subject concerns another resource than the one given with it
     */
    public function decideAbilities(Actor $actor, string $ability, Realm $realm, array $asked, Origin $origin): array
    {
        $at = $this->clock->now();
        // One actor, proven once for the batch.
        $proven = $this->proveActor($actor);
        $requests = array_map(fn (array $one): Request => $this->provenAs(Request::forAbility($ability, $realm, $one[0], $actor, $origin, $at, $one[1]), $proven), $asked);

        if ([] === $requests) {
            return [];
        }

        $sides = [];

        foreach ($requests as $request) {
            $both = $this->sides($request);
            array_push($sides, ...$both);

            if ($request->actor instanceof ViewingAs) {
                $this->sides[$request] = $both;
            }
        }

        // One realm, one moment: the versions are read once, before anything is read for the batch, and
        // the batch reads at them.
        $before = $this->versionsOf($requests[0]);
        // Each request's attributes are its own: resolved, and recorded, for it.
        $judged = $this->scope->within($requests[0]->realm, $before, function () use ($sides, $requests): array {
            $this->preload($sides);

            return array_map(fn (Request $request): array => $this->scope->judging(fn (): Decision => $this->judge($request)), $requests);
        });
        $checks = array_map(fn (Request $request, array $one): bool => $this->checksWhatMoved($request, $one[0]), $requests, $judged);
        $read = \in_array(true, $checks, true) ? ReadSet::between($before, $this->versionsOf($requests[0])) : null;
        $unchecked = ReadSet::unchecked($before);
        $decided = array_map(fn (Request $request, array $one, bool $checks): array => [$request, self::identified($one[0], (string) $this->ids->next(), $request->at, $checks && null !== $read ? $this->withAttributes($request, $read, $one[1]) : $unchecked)], $requests, $judged, $checks);

        return null === $this->decisions ? array_map(static fn (array $one): Decision => $one[1], $decided) : $this->decisions->recordAll($decided);
    }

    /**
     * Gives the authority check the requests, and each element the requests it matches, if they
     * preload. One that fails has read nothing: each request then reads for itself, and reports what
     * it can't.
     *
     * @param non-empty-list<Request> $requests
     */
    private function preload(array $requests): void
    {
        if ($this->authority instanceof Preloads) {
            self::preloading($this->authority, $requests);
        }

        foreach ($this->elements as $element) {
            if ($element instanceof Preloads && (!$element instanceof PermitElement || \in_array($element->basis(), $requests[0]->authorizedBy(), true))) {
                self::preloading($element, $requests, $element->matches(...));
            }
        }
    }

    /**
     * @param non-empty-list<Request>        $requests
     * @param (\Closure(Request): bool)|null $matches  Which of them it's given, if not all
     */
    private static function preloading(Preloads $preloads, array $requests, ?\Closure $matches = null): void
    {
        try {
            $matching = null === $matches ? $requests : array_values(array_filter($requests, $matches));

            if ([] !== $matching) {
                $preloads->preload($matching);
            }
        } catch (IntegratorError $error) {
            throw $error;
        } catch (\Throwable) {
            // Each request reads for itself.
        }
    }

    /**
     * Decides, identifies the decision and records it: an operation's before it runs.
     */
    private function evaluate(Request $request): Decision
    {
        $request = $this->provenAs($request, $this->proveActor($request->actor));
        $decision = $this->identify($request);

        return null === $this->decisions ? $decision : $this->decisions->record($request, $decision);
    }

    /**
     * The actor as recorded, or why it isn't one (D-45): core proves its own actors, a ProvesActors the
     * others; one nothing proves is unknown. A view-as is proven by its viewer.
     */
    private function proveActor(Actor $actor): Actor|Truth
    {
        if ($actor instanceof ViewingAs) {
            $viewer = $this->proveActor($actor->viewer);

            return $viewer instanceof Truth ? $viewer : new ViewingAs($viewer, $actor->target);
        }

        // The host's operator is proven by its origin (OperatorPermit); no one, by nothing; a principal a
        // permit acts as, by the permit (Permit::cause()), and in a decision it proves nothing (L-4).
        if ($actor instanceof Operator || $actor instanceof Anonymous || $actor instanceof ActingPrincipal) {
            return $actor;
        }

        foreach ($this->actors as $prover) {
            if ($prover->proves($actor)) {
                try {
                    return $prover->prove($actor);
                } catch (\Throwable $exception) {
                    // A store that's down, as for any element: unknown, not refused; a mistake in the
                    // setup is thrown on (safely()).
                    return self::safely(static fn (): never => throw $exception);
                }
            }
        }

        if (true !== ($this->warned[$actor::class] ?? null)) {
            $this->warned[$actor::class] = true;

            $this->logger->warning('YorvoID can\'t prove actors of {class}: their decisions are Indeterminate (unproven_actor).', ['class' => $actor::class]);
        }

        return Truth::unknown(IndeterminateReason::UnprovenActor, \sprintf('Nothing proves actors of %s.', $actor::class))->grounded(Ground::authority('unproven'));
    }

    /**
     * The request by the proven actor, or the request as asked, remembered as unproven.
     */
    private function provenAs(Request $request, Actor|Truth $proven): Request
    {
        if ($proven instanceof Actor) {
            return $proven === $request->actor ? $request : $request->withActor($proven);
        }

        $this->unproven[$request] = $proven;

        return $request;
    }

    /**
     * Decides and identifies the decision.
     */
    private function identify(Request $request): Decision
    {
        return $this->judged($request, fn (): Decision => $this->judge($request));
    }

    /**
     * Judges at the versions read before (D-44): the stores read at them, and what moved while it judged
     * is read after. Identified, with that read set.
     *
     * @param \Closure(): Decision $judge
     */
    private function judged(Request $request, \Closure $judge): Decision
    {
        $before = $this->versionsOf($request);
        [$decision, $attributes] = $this->scope->within($request->realm, $before, fn (): array => $this->scope->judging($judge));
        $read = $this->checksWhatMoved($request, $decision) ? $this->withAttributes($request, ReadSet::between($before, $this->versionsOf($request)), $attributes) : ReadSet::unchecked($before);

        return self::identified($decision, (string) $this->ids->next(), $request->at, $read);
    }

    /**
     * A checked read set, with the attributes the decision read as its record holds them (M-9): only a
     * checked one, since nothing reads an unchecked one's.
     *
     * @param array<string, array{string|int|bool|list<string>, bool}> $attributes By name, the value and whether it's public
     */
    private function withAttributes(Request $request, ReadSet $read, array $attributes): ReadSet
    {
        $recorded = [];

        foreach ($attributes as $name => [$value, $public]) {
            $recorded[$name] = $this->macs->record($request->realm, $name, $value, $public);
        }

        return $read->withAttributes($recorded);
    }

    /**
     * Whether what moved while the decision judged is checked, by reading the versions again (D-44): the
     * one place that says so. It is, wherever something uses the decision beyond showing it: an
     * operation's decision, which a permit and its consequences carry; a refusal and a Permit-offline,
     * which are always recorded; a view as someone else; an ability's Permit the log records. Only an
     * ability's Permit that's neither recorded nor acted on, e.g. isGranted() for a link, isn't.
     */
    private function checksWhatMoved(Request $request, Decision $decision): bool
    {
        if (null !== $request->operation || $request->actor instanceof ViewingAs) {
            return true;
        }

        return match ($decision->verdict) {
            Verdict::Permit => $this->decisions?->recordsPermit($request) ?? false,
            Verdict::PermitOffline, Verdict::Deny, Verdict::Indeterminate => true,
        };
    }

    /**
     * The versions of what the decision reads, as they are now, as no decision reads them: a source that
     * can't tell is left out, and the decision itself comes from the elements, which report what they
     * couldn't read.
     *
     * @return array<string, int>
     */
    private function versionsOf(Request $request): array
    {
        return $this->scope->outside(fn (): array => $this->versionsNow($request));
    }

    /**
     * @return array<string, int>
     */
    private function versionsNow(Request $request): array
    {
        $versions = [];
        /** @var array<int, array{VersionStore, list<string>}> $stored The sources kept in each VersionStore, read at once */
        $stored = [];
        /** @var list<VersionSource> $own The others, each read on its own */
        $own = [];

        foreach ($this->versions as $source) {
            $store = $source instanceof KeepsVersionsIn ? $source->versionStore() : null;

            if (null === $store) {
                $own[] = $source;
            } else {
                $stored[spl_object_id($store)][0] = $store;
                $stored[spl_object_id($store)][1][] = $source->name();
            }
        }

        foreach ($stored as [$store, $names]) {
            try {
                $versions = [...$versions, ...$store->versions($request->realm, $names)];
            } catch (\Throwable $e) {
                $this->logger->warning('YorvoID couldn\'t read the {source} version: {message}', ['source' => implode(', ', $names), 'message' => $e->getMessage()]);
            }
        }

        foreach ($own as $source) {
            try {
                $versions[$source->name()] = $source->version($request->realm);
            } catch (\Throwable $e) {
                $this->logger->warning('YorvoID couldn\'t read the {source} version: {message}', ['source' => $source->name(), 'message' => $e->getMessage()]);
            }
        }

        return $versions;
    }

    /**
     * Who the elements decide for: the actor, or both sides of a view-as. A viewer that isn't proven views
     * nothing: each side is judged by why it isn't (D-45).
     *
     * @return non-empty-list<Request>
     */
    private function sides(Request $request): array
    {
        $actor = $request->actor;

        if (!$actor instanceof ViewingAs) {
            return [$request];
        }

        $sides = [$request->by($actor->viewer), $request->by($actor->target)];

        if (null !== ($unproven = $this->unproven[$request] ?? null)) {
            foreach ($sides as $side) {
                $this->unproven[$side] = $unproven;
            }
        }

        return $sides;
    }

    private function judge(Request $request): Decision
    {
        $actor = $request->actor;

        if ($actor instanceof ViewingAs) {
            return $this->viewingAs($request, $actor);
        }

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

        // An actor that isn't what it claims has no authority to check (D-45).
        $authority = $this->unproven[$request] ?? self::safely(fn (): Truth => $this->authority->check($request));
        $reasons[] = new Reason('authority', 'authority', $authority);
        $decision = $this->precedence($request, $forbids, $authority, $permits, $reasons);

        if ([] === $decision->indeterminate) {
            return $decision;
        }

        $this->logger->warning('YorvoID couldn\'t decide whether to run "{operation}": {reason}', ['operation' => $request->name(), 'reason' => $decision->reason, 'indeterminate' => array_map(static fn (IndeterminateReason $reason): string => $reason->value, $decision->indeterminate)]);
        $offline = $this->offline($request, $decision, $permits);

        if (null === $offline) {
            return $decision;
        }

        $this->logger->warning('YorvoID permitted "{operation}" offline: {reason}', ['operation' => $request->name(), 'reason' => $offline->reason]);

        return $offline;
    }

    /**
     * What a consequence is refused by: an actor no longer proven, or a forbid that applies to
     * consequences; otherwise it's permitted, by its parent.
     */
    private function consequence(Request $request, Decision $parent): Decision
    {
        if (null !== ($unproven = $this->unproven[$request] ?? null)) {
            return Decision::indeterminate(self::why([], [$unproven]), \sprintf('The actor\'s authority couldn\'t be established: %s', $unproven->detail), [new Reason('authority', 'authority', $unproven)], Precedence::AuthorityUnknown);
        }

        $reasons = [];
        $forbids = ['holds' => [], 'unknown' => []];

        foreach ($this->elements as $element) {
            if (!$element instanceof AppliesToConsequences) {
                continue;
            }

            $truth = self::safely(static fn (): ?Truth => $element->matches($request) ? null : Truth::fails('Not about this request.'));
            $truth ??= self::safely(static fn (): Truth => $element->evaluate($request));

            if (TruthValue::Fails !== $truth->value) {
                $forbids[TruthValue::Holds === $truth->value ? 'holds' : 'unknown'][] = $truth;
                $reasons[] = new Reason($element->name(), 'forbid', $truth);
            }
        }

        if ([] !== $forbids['holds']) {
            return Decision::deny(\sprintf('A forbid applies: %s', $forbids['holds'][0]->detail), $reasons, precedence: Precedence::ForbidHolds);
        }

        if ([] !== $forbids['unknown']) {
            return Decision::indeterminate(self::why([IndeterminateReason::UnknownForbid], $forbids['unknown']), \sprintf('A forbid couldn\'t be ruled out: %s', $forbids['unknown'][0]->detail), $reasons, Precedence::ForbidUnknown);
        }

        return self::permitted(AuthorizedBy::Consequence, \sprintf('A consequence of a permitted operation (%s).', $parent->id), $reasons, Precedence::PermitHolds);
    }

    /**
     * Read-only, and never more than the viewer may: see the class.
     */
    private function viewingAs(Request $request, ViewingAs $actor): Decision
    {
        if (null !== $request->operation) {
            $truth = Truth::holds('A view as someone else is read-only: no operation runs for it.');

            return Decision::deny(\sprintf('A forbid applies: %s', $truth->detail), [new Reason(ViewingAs::READ_ONLY, 'forbid', $truth)], precedence: Precedence::ForbidHolds);
        }

        // A Request is the key elements keep what they preloaded by: the same sides, if it was a batch.
        [$viewerSide, $targetSide] = $this->sides[$request] ?? $this->sides($request);

        $viewer = $this->judge($viewerSide);
        $target = $this->judge($targetSide);

        // Only an Indeterminate has reasons it couldn't decide. Permit-offline never comes of an ability.
        if ([] !== $target->indeterminate || ([] === $viewer->indeterminate && (!$target->permits() || $viewer->permits()))) {
            return self::seen($target, $viewer);
        }

        // The target may, or the viewer couldn't be decided: the viewer's side decides, with the
        // viewer's own remedies and none of the target's.
        return [] !== $viewer->indeterminate
            ? self::seen(self::decision(Verdict::Indeterminate, null, \sprintf('Viewing as someone else, the viewer\'s own access couldn\'t be decided: %s', $viewer->reason), $viewer->reasons, indeterminate: $viewer->indeterminate, precedence: $viewer->precedence), $viewer)
            : self::seen(Decision::deny(\sprintf('Viewing as someone else, the viewer may not: %s', $viewer->reason), $viewer->reasons, $viewer->remedies, $viewer->precedence), $viewer);
    }

    private static function seen(Decision $decided, Decision $viewer): Decision
    {
        return self::decision($decided->verdict, $decided->basis, $decided->reason, $decided->reasons, $decided->remedies, $decided->indeterminate, precedence: $decided->precedence, viewer: $viewer);
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

        return self::decision(Verdict::PermitOffline, $basis, \sprintf('Permitted offline: %s', $truth->detail), [...$decision->reasons, new Reason('offline', 'offline', $truth)], precedence: Precedence::Offline);
    }

    /**
     * @param array<string, list<Truth>>                       $forbids
     * @param array<string, list<array{PermitElement, Truth}>> $permits
     * @param list<Reason>                                     $reasons
     */
    private function precedence(Request $request, array $forbids, Truth $authority, array $permits, array $reasons): Decision
    {
        // 1. With what would lift the forbids, only if each has a remedy and nothing else would refuse: a
        // remedy is a promise.
        if ([] !== $forbids['holds']) {
            $remedies = [];

            foreach ($forbids['holds'] as $truth) {
                if ([] === $truth->remedies) {
                    $remedies = [];

                    break;
                }

                array_push($remedies, ...$truth->remedies);
            }

            $lifted = [] === $remedies ? null : $this->precedence($request, ['holds' => [], 'unknown' => $forbids['unknown']], $authority, $permits, $reasons);

            return Decision::deny(\sprintf('A forbid applies: %s', $forbids['holds'][0]->detail), $reasons, Precedence::PermitHolds === $lifted?->precedence ? self::once($remedies) : [], Precedence::ForbidHolds);
        }

        $couldPermit = [] !== $permits['holds'] || [] !== $permits['unknown'];

        // 2. Reported even when nothing could permit it (Q-09): the unknown is an operational signal.
        if ([] !== $forbids['unknown']) {
            return Decision::indeterminate(
                self::why([IndeterminateReason::UnknownForbid], $forbids['unknown']),
                \sprintf('A forbid couldn\'t be ruled out: %s%s', $forbids['unknown'][0]->detail, $couldPermit && TruthValue::Fails !== $authority->value ? '' : ' (refused regardless: nothing could permit it)'),
                $reasons,
                Precedence::ForbidUnknown,
            );
        }

        // 3. and 4.
        if (TruthValue::Fails === $authority->value) {
            return Decision::deny(\sprintf('The actor\'s authority is invalid: %s', $authority->detail), $reasons, precedence: Precedence::AuthorityInvalid);
        }

        if (TruthValue::Unknown === $authority->value) {
            return Decision::indeterminate(self::why([], [$authority]), \sprintf('The actor\'s authority couldn\'t be established: %s', $authority->detail), $reasons, Precedence::AuthorityUnknown);
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

            return self::permitted($chosen[0]->basis(), $chosen[1]->detail, $reasons, Precedence::PermitHolds);
        }

        // 6.
        if ([] !== $permits['unknown']) {
            return Decision::indeterminate(
                self::why([], array_map(static fn (array $permit): Truth => $permit[1], $permits['unknown'])),
                \sprintf('A permit couldn\'t be evaluated: %s', $permits['unknown'][0][1]->detail),
                $reasons,
                Precedence::PermitUnknown,
            );
        }

        // 7. Each remedy once, however many permits ask for it.
        $remedies = [];

        foreach ($permits['fails'] as [, $truth]) {
            array_push($remedies, ...$truth->remedies);
        }

        return Decision::deny(
            [] === $permits['fails'] ? \sprintf('No permit applies to "%s".', $request->name()) : $permits['fails'][0][1]->detail,
            $reasons,
            self::once($remedies),
            Precedence::NoPermit,
        );
    }

    /**
     * Each remedy once.
     *
     * @param list<Remedy> $remedies
     *
     * @return list<Remedy>
     */
    private static function once(array $remedies): array
    {
        $once = [];

        foreach ($remedies as $remedy) {
            $once[json_encode([$remedy->name(), $remedy->parameters()], \JSON_THROW_ON_ERROR)] ??= $remedy;
        }

        return array_values($once);
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
     * A decision of any verdict: only here, since Decision's constructor is private to everything else,
     * so that nothing but the evaluator permits (D-50).
     *
     * @param list<Reason>              $reasons
     * @param list<Remedy>              $remedies
     * @param list<IndeterminateReason> $indeterminate
     */
    private static function decision(Verdict $verdict, ?AuthorizedBy $basis, string $reason, array $reasons = [], array $remedies = [], array $indeterminate = [], ?string $id = null, ?\DateTimeImmutable $at = null, ReadSet $read = new ReadSet(), ?string $hash = null, ?Precedence $precedence = null, ?Decision $viewer = null): Decision
    {
        $make = \Closure::bind(static fn (): Decision => new Decision($verdict, $basis, $reason, $reasons, $remedies, $indeterminate, $id, $at, $read, $hash, $precedence, $viewer), null, Decision::class);

        return $make();
    }

    /**
     * The decision with its ID, its time and what it read: only here and in the decision log, for a
     * decision that permits.
     */
    private static function identified(Decision $decision, string $id, \DateTimeImmutable $at, ReadSet $read): Decision
    {
        $identify = \Closure::bind(static fn (): Decision => $decision->withIdentity($id, $at, $read), null, Decision::class);

        return $identify();
    }

    /**
     * The decision, for this command and this actor, the very objects.
     *
     * @param Command<mixed> $command
     */
    private static function bound(Decision $decision, Command $command, Actor $actor): Decision
    {
        $bind = \Closure::bind(static fn (): Decision => $decision->boundTo($command, $actor), null, Decision::class);

        return $bind();
    }

    /**
     * @param list<Reason> $reasons
     */
    private static function permitted(AuthorizedBy $basis, string $reason, array $reasons, Precedence $precedence): Decision
    {
        return self::decision(Verdict::Permit, $basis, $reason, $reasons, precedence: $precedence);
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
