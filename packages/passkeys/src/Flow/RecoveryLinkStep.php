<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Recovery\UseRecoveryLink;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Step;
use YorvoID\Flows\StepResult;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\RecoveryLinkView;

/**
 * Recovers an account with a recovery link (ATN-030). Start the flow with the link's token as
 * "recovery_link.token"; the person confirms, so an email scanner opening the link doesn't use it up.
 * Every other session ends.
 *
 * @api
 */
final readonly class RecoveryLinkStep implements Step
{
    public const string NAME = 'recovery_link';

    public const string TOKEN = self::NAME.'.token';

    private const string ACCOUNT = self::NAME.'.account';

    /**
     * @param Principals|null $principals To name the account on the page; without it, it isn't named
     */
    public function __construct(
        private Operations $operations,
        private RecoveryLinks $links,
        private ?Principals $principals = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function applies(FlowState $state, FlowContext $context): bool
    {
        return true;
    }

    /**
     * Names the account the link is for, so the person sees whose account they'd be signed in to.
     */
    public function enter(FlowState $state, FlowContext $context): FlowState
    {
        $token = $state->string(self::TOKEN) ?? '';
        $principal = '' === $token ? null : $this->links->peek($state->realm, Secret::fromString($token));
        $identifiers = null === $principal || null === $this->principals ? [] : $this->principals->identifiers($principal->realm, $principal->id);
        $account = $identifiers[IdentifierKind::Email->value] ?? $identifiers[IdentifierKind::Username->value] ?? null;

        return $state->with([self::ACCOUNT => $account?->value]);
    }

    public function view(FlowState $state): StepView
    {
        return new RecoveryLinkView($state->string(self::ACCOUNT));
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $token = $state->string(self::TOKEN) ?? '';
        $started = '' === $token ? null : $this->operations->run(new UseRecoveryLink($state->realm, Secret::fromString($token), $context->device, $context->address, $context->deviceCookie), new Anonymous(), $context->origin);

        return null === $started ? StepResult::failed(new Problem('yorvoid.flow.recovery_link.not_working')) : StepResult::done(forget: [self::TOKEN, self::ACCOUNT], signedIn: $started);
    }
}
