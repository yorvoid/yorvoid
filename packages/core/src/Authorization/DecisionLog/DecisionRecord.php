<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Crypto\Digest;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Realm;
use YorvoID\Core\Text;

/**
 * One decision as the decision log keeps it (AUZ-040): who asked for what, the outcome, why, and the
 * versions it read. The elements' detail texts aren't kept, since they can hold data; their names and
 * truth values are. Its hash is over a versioned canonical form, which the security-log entries of
 * what it permitted cite (ADR-09).
 *
 * @api
 */
final readonly class DecisionRecord
{
    /** Format 3 (0.5, D-50): a consequence's parent. Format 2 (D-42): attempts, with their details, and the read set */
    public const int FORMAT = 3;

    public const string ALGORITHM = 'sha256';

    /**
     * @param 'operation'|'ability'|'attempt'                                                                     $kind
     * @param string                                                                                              $name          The operation's name, or the ability's
     * @param string|null                                                                                         $actor         The acting principal's ID, if there's one
     * @param list<string>                                                                                        $indeterminate Why it couldn't decide
     * @param list<string>                                                                                        $remedies      Their names
     * @param list<array{string, string, string}>                                                                 $elements      Each element that matched, and the authority check: source, effect, truth value
     * @param array<string, int>                                                                                  $versions
     * @param array{string, string}                                                                               $origin        Its kind and correlation ID
     * @param array{actor: string|null, verdict: string|null, elements: list<array{string, string, string}>}|null $viewer        Viewing as
     *                                                                                                                           someone else: the viewer, and its own decision if one was made
     *                                                                                                                           (none for an operation, which is refused); the actor is then the target
     * @param array<string, mixed>                                                                                $details       An attempt's facts, as its log entry had them (format 2)
     * @param string|null                                                                                         $parent        A consequence's: the decision of the operation that caused it (format 3)
     * @param int                                                                                                 $format        The canonical form it's hashed over: 1 for records written before 0.5
     */
    public function __construct(
        public string $id,
        public Realm $realm,
        public \DateTimeImmutable $at,
        public string $kind,
        public string $name,
        public ?string $actor,
        public ?ResourceRef $resource,
        public string $verdict,
        public ?string $basis,
        public string $reason,
        public array $indeterminate,
        public array $remedies,
        public array $elements,
        public array $versions,
        public array $origin,
        public ?array $viewer = null,
        public array $details = [],
        public ?string $parent = null,
        public int $format = self::FORMAT,
    ) {
    }

    /**
     * @throws IntegratorError When the decision has no ID or time: only an identified decision is recorded
     */
    public static function of(Request $request, Decision $decision, ?string $parent = null): self
    {
        if (null === $decision->id || null === $decision->at) {
            throw new IntegratorError('Only a decision with an ID and a time can be recorded.', 'Record the decisions the evaluator makes: it identifies them.', 'concepts/security-log.md#the-decision-log');
        }

        return new self(
            $decision->id,
            $request->realm,
            $decision->at,
            null === $request->operation ? 'ability' : 'operation',
            $request->name(),
            null === $request->actor->principalId ? null : (string) $request->actor->principalId,
            $request->resource,
            $decision->verdict->value,
            $decision->basis?->value,
            Text::utf8($decision->reason),
            array_map(static fn (IndeterminateReason $reason): string => $reason->value, $decision->indeterminate),
            array_map(static fn (Remedy $remedy): string => $remedy->name(), $decision->remedies),
            self::reasons($decision),
            $decision->versions,
            [$request->origin->kind->value, $request->origin->correlationId],
            self::viewer($request, $decision),
            parent: $parent,
        );
    }

    /**
     * A failed attempt or a refusal of something that isn't an operation's decision (D-42): a wrong
     * code, a passkey nobody has, a throttled form. Its verdict is the outcome ("failed", "denied").
     *
     * @param array<string, mixed> $details As LogEntry::checked() left them
     */
    public static function attempt(string $id, Realm $realm, \DateTimeImmutable $at, string $action, string $outcome, ?string $actor, ?string $subjectType, ?string $subjectId, Origin $origin, array $details): self
    {
        $subject = null === $subjectType || null === $subjectId ? null : new ResourceRef($subjectType, $subjectId);

        return new self($id, $realm, $at, 'attempt', $action, $actor, $subject, $outcome, null, '', [], [], [], [], [$origin->kind->value, $origin->correlationId], null, $details);
    }

    /**
     * @return array{actor: string|null, verdict: string|null, elements: list<array{string, string, string}>}|null
     */
    private static function viewer(Request $request, Decision $decision): ?array
    {
        $actor = $request->actor;

        if (!$actor instanceof ViewingAs) {
            return null;
        }

        return [
            'actor' => null === $actor->viewer->principalId ? null : (string) $actor->viewer->principalId,
            'verdict' => $decision->viewer?->verdict->value,
            'elements' => null === $decision->viewer ? [] : self::reasons($decision->viewer),
        ];
    }

    /**
     * @return list<array{string, string, string}>
     */
    private static function reasons(Decision $decision): array
    {
        return array_map(static fn (Reason $reason): array => [$reason->source, $reason->effect, $reason->truth->value->value], $decision->reasons);
    }

    /**
     * The canonical form: what's hashed and stored.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $versions = $this->versions;
        ksort($versions, \SORT_STRING);

        $form = [
            'format' => $this->format,
            'id' => $this->id,
            'realm' => $this->realm->name,
            'decided_at' => $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'kind' => $this->kind,
            'name' => $this->name,
            'actor' => $this->actor,
            'resource' => null === $this->resource ? null : [$this->resource->type, $this->resource->id],
            'verdict' => $this->verdict,
            'basis' => $this->basis,
            'reason' => $this->reason,
            'indeterminate' => $this->indeterminate,
            'remedies' => $this->remedies,
            'elements' => $this->elements,
            'versions' => $versions,
            'origin' => $this->origin,
            'viewer' => $this->viewer,
        ];

        // Format 1 is hashed over exactly what it held; format 2 adds the details and the read set (G-3 fills it).
        if ($this->format >= 2) {
            $form['details'] = $this->details;
            $form['read'] = ['sources' => $versions];
        }

        if ($this->format >= 3) {
            $form['parent'] = $this->parent;
        }

        return $form;
    }

    public function json(): string
    {
        return json_encode($this->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    public function hash(): string
    {
        return Digest::of(self::ALGORITHM, $this->json());
    }

    /**
     * A record from its canonical form, as stored.
     *
     * @throws \UnexpectedValueException When it isn't one
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('A stored decision isn\'t valid JSON.', previous: $e);
        }

        if (!\is_array($data) || !\in_array($data['format'] ?? null, [1, 2, 3], true)) {
            throw new \UnexpectedValueException('A stored decision has an unknown format.');
        }

        $at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', self::string($data, 'decided_at'), new \DateTimeZone('UTC'));
        $resource = $data['resource'] ?? null;
        $kind = self::string($data, 'kind');

        $format = $data['format'];

        if (false === $at || !\in_array($kind, $format >= 2 ? ['operation', 'ability', 'attempt'] : ['operation', 'ability'], true)) {
            throw new \UnexpectedValueException('A stored decision is malformed.');
        }

        return new self(
            self::string($data, 'id'),
            new Realm(self::string($data, 'realm')),
            $at,
            $kind,
            self::string($data, 'name'),
            self::nullableString($data, 'actor'),
            \is_array($resource) && \is_string($resource[0] ?? null) && \is_string($resource[1] ?? null) ? new ResourceRef($resource[0], $resource[1]) : null,
            self::string($data, 'verdict'),
            self::nullableString($data, 'basis'),
            self::string($data, 'reason'),
            self::strings($data, 'indeterminate'),
            self::strings($data, 'remedies'),
            self::elements($data),
            self::versions($data),
            self::origin($data),
            self::viewerOf($data),
            $format >= 2 ? self::detailsOf($data) : [],
            $format >= 3 ? self::nullableString($data, 'parent') : null,
            $format,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function detailsOf(array $data): array
    {
        $details = $data['details'] ?? [];

        if (!\is_array($details) || ([] !== $details && array_is_list($details))) {
            throw new \UnexpectedValueException('A stored decision\'s details are malformed.');
        }

        $keyed = [];

        foreach ($details as $key => $value) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('A stored decision\'s details are malformed.');
            }

            $keyed[$key] = $value;
        }

        return $keyed;
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : throw new \UnexpectedValueException(\sprintf('A stored decision\'s "%s" isn\'t text.', $key));
    }

    /**
     * @param array<mixed> $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        return null === ($data[$key] ?? null) ? null : self::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $strings = [];

        foreach (\is_array($value) && array_is_list($value) ? $value : throw new \UnexpectedValueException(\sprintf('A stored decision\'s "%s" isn\'t a list.', $key)) as $item) {
            $strings[] = \is_string($item) ? $item : throw new \UnexpectedValueException(\sprintf('A stored decision\'s "%s" holds something else than text.', $key));
        }

        return $strings;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array{string, string, string}>
     */
    private static function elements(array $data): array
    {
        $elements = [];

        foreach (\is_array($data['elements'] ?? null) ? $data['elements'] : throw new \UnexpectedValueException('A stored decision\'s elements aren\'t a list.') as $element) {
            if (!\is_array($element) || !\is_string($element[0] ?? null) || !\is_string($element[1] ?? null) || !\is_string($element[2] ?? null)) {
                throw new \UnexpectedValueException('A stored decision\'s element is malformed.');
            }

            $elements[] = [$element[0], $element[1], $element[2]];
        }

        return $elements;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{actor: string|null, verdict: string|null, elements: list<array{string, string, string}>}|null
     */
    private static function viewerOf(array $data): ?array
    {
        $viewer = $data['viewer'] ?? null;

        if (null === $viewer) {
            return null;
        }

        if (!\is_array($viewer)) {
            throw new \UnexpectedValueException('A stored decision\'s viewer is malformed.');
        }

        return ['actor' => self::nullableString($viewer, 'actor'), 'verdict' => self::nullableString($viewer, 'verdict'), 'elements' => self::elements($viewer)];
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, int>
     */
    private static function versions(array $data): array
    {
        $versions = [];

        foreach (\is_array($data['versions'] ?? null) ? $data['versions'] : throw new \UnexpectedValueException('A stored decision\'s versions aren\'t a map.') as $source => $version) {
            if (!\is_string($source) || !\is_int($version)) {
                throw new \UnexpectedValueException('A stored decision\'s versions are malformed.');
            }

            $versions[$source] = $version;
        }

        return $versions;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{string, string}
     */
    private static function origin(array $data): array
    {
        $origin = $data['origin'] ?? null;

        if (!\is_array($origin) || !\is_string($origin[0] ?? null) || !\is_string($origin[1] ?? null)) {
            throw new \UnexpectedValueException('A stored decision\'s origin is malformed.');
        }

        return [$origin[0], $origin[1]];
    }
}
