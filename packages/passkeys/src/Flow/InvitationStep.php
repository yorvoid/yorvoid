<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use Psr\Clock\ClockInterface;
use YorvoID\Authn\Invitation\Invitations;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
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
use YorvoID\Passkeys\Flow\View\InvitationView;

/**
 * Accepts an invitation (ATN-041), and signs the invitee in. Start the flow with the link's token as
 * "invitation.token"; the person confirms, so an email scanner opening the link doesn't use it up.
 *
 * @api
 */
final readonly class InvitationStep implements Step
{
    public const string NAME = 'invitation';

    public const string TOKEN = self::NAME.'.token';

    private const string EMAIL = self::NAME.'.email';

    public function __construct(
        private Invitations $invitations,
        private Principals $principals,
        private Sessions $sessions,
        private ClockInterface $clock,
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

    public function enter(FlowState $state, FlowContext $context): FlowState
    {
        $token = $state->string(self::TOKEN) ?? '';
        $invitee = '' === $token ? null : $this->invitations->peek($state->realm, Secret::fromString($token));
        $email = null === $invitee ? null : $this->principals->identifiers($invitee->realm, $invitee->id)[IdentifierKind::Email->value] ?? null;

        return $state->with([self::EMAIL => $email?->value]);
    }

    public function view(FlowState $state): StepView
    {
        return new InvitationView($state->string(self::EMAIL));
    }

    public function submit(FlowState $state, FlowContext $context, Input $input): StepResult
    {
        $token = $state->string(self::TOKEN) ?? '';
        $invitee = '' === $token ? null : $this->invitations->accept($state->realm, Secret::fromString($token), $context->origin);

        if (null === $invitee) {
            return StepResult::failed(new Problem('yorvoid.flow.invitation.not_working'));
        }

        return StepResult::done(forget: [self::TOKEN], signedIn: $this->sessions->start(new StartSession($state->realm, $invitee->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::EmailLink, $this->clock->now())), $context->device, $context->address, $context->deviceCookie), $context->origin));
    }
}
