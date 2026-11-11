<?php

declare(strict_types=1);

namespace YorvoID\Tests\Passkeys\Flow;

use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Invitation\Invitations;
use YorvoID\Authn\Invitation\Invite;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\Flows;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStatus;
use YorvoID\Flows\Input;
use YorvoID\Flows\Notice;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Progress;
use YorvoID\Flows\Storage\InMemoryFlowStore;
use YorvoID\Mail\InMemoryMailer;
use YorvoID\Notifications\EmailVerification;
use YorvoID\Notifications\NotificationSettings;
use YorvoID\Notifications\Translations;
use YorvoID\Passkeys\Flow\InvitationStep;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Flow\RecoveryCodesStep;
use YorvoID\Passkeys\Flow\RecoveryCodeStep;
use YorvoID\Passkeys\Flow\RecoveryLinkStep;
use YorvoID\Passkeys\Flow\View\InvitationView;
use YorvoID\Passkeys\Flow\View\PasskeyRegistrationView;
use YorvoID\Passkeys\Flow\View\PasskeySignInView;
use YorvoID\Passkeys\Flow\View\RecoveryCodesView;
use YorvoID\Passkeys\Flow\View\StepUpView;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Refusal;
use YorvoID\Passkeys\RegisterPasskey;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\Stack;
use YorvoID\Tests\Support\TestOperations;
use YorvoID\Throttle\Throttle;
use YorvoID\Translation\Translator;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * The passkey-first journeys, end to end, with a virtual authenticator.
 */
final class PasskeyFlowsTest extends DatabaseTestCase
{
    private MockClock $clock;

    private Flows $flows;

    private Principals $principals;

    private Sessions $sessions;

    private Passkeys $passkeys;

    private RecoveryCodes $recoveryCodes;

    private RecoveryLinks $recoveryLinks;

    private SetupCodes $setupCodes;

    private Invitations $invitations;

    private Credentials $credentials;

    private PasskeyFlows $journeys;

    private FlowContext $context;

    private Realm $realm;

    private Stack $stack;

    private Operations $operations;

    protected function setUp(): void
    {
        $this->stack = $stack = $this->stack('memory');
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');
        $this->realm = new Realm('acme');
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($stack->log, $stack->transactions, clock: $this->clock);
        $attempts = new Attempts(new Throttle($stack->throttle, $this->clock));
        $this->principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($stack->sessions, $this->principals, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $stepUp = new StepUp($this->sessions, $stack->coolDowns, $log, $this->clock);
        $this->credentials = $credentials = new Credentials($stack->credentials, $this->sessions, $stepUp, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $this->passkeys = new Passkeys($stack->passkeys, $stack->ceremonies, $credentials, $this->sessions, $this->principals, $log, $stack->transactions, new PasskeyPolicy('example.com', ['https://example.com']), $attempts, $ids, $this->clock);
        $this->recoveryCodes = new RecoveryCodes($stack->recoveryCodes, $credentials, $this->sessions, $stepUp, $attempts, $log, $stack->transactions, $this->principals, clock: $this->clock);
        $tokens = new OneTimeTokens($stack->tokens, $this->principals, $log, $stack->transactions, clock: $this->clock);
        $this->recoveryLinks = new RecoveryLinks($tokens, $this->sessions, $this->principals, $stepUp, $stack->transactions, $this->clock);
        $this->setupCodes = new SetupCodes($stack->setup, $this->principals, $log, $stack->transactions, $attempts, $this->sessions, $this->clock);
        $this->invitations = new Invitations($this->principals, $tokens, $stack->transactions, new Throttle($stack->throttle, $this->clock), $this->sessions, clock: $this->clock);
        $verification = new EmailVerification($this->principals, $tokens, new InMemoryMailer(), new Translator([Translations::DIRECTORY]), new NotificationSettings('Acme', 'https://acme.example/not-me?token={token}'), $stack->transactions, static fn (string $token): string => 'https://acme.example/verify?token='.$token);
        $this->operations = TestOperations::of($log, $stack->principals, $stepUp, $this->principals, $this->sessions, $credentials, $this->passkeys, $this->recoveryCodes, $this->recoveryLinks, $tokens, $this->setupCodes, $this->invitations, $verification);
        $this->journeys = $journeys = new PasskeyFlows($this->operations, $this->passkeys, $this->principals, $this->invitations, $this->recoveryCodes, $this->recoveryLinks, $stepUp, Duration::minutes(5), $verification);
        $this->flows = new Flows($journeys->all(), new InMemoryFlowStore(), $this->sessions, clock: $this->clock);
        $this->context = new FlowContext(new Origin(OriginKind::Web), device: 'Firefox on Linux', address: '192.0.2.10');
    }

    public function testClaimsAFreshInstallation(): void
    {
        $code = Permits::call($this->setupCodes->create(...), new CreateSetupCode($this->realm, Duration::days(1)), new Origin(OriginKind::Console));
        $flow = $this->flows->start(PasskeyFlows::SETUP, $this->realm, $this->context);
        self::assertSame('setup_code', $flow->step);
        self::assertSame([], $flow->view?->jsonSerialize());

        self::assertEquals([new Problem('yorvoid.flow.setup_code.invalid', field: 'code')], $this->submit($flow, 'setup_code', ['code' => 'WRONG-CODE', 'email' => 'ana@example.com'])->problems);
        self::assertEquals([new Problem('yorvoid.flow.email.invalid', field: 'email')], $this->submit($flow, 'setup_code', ['code' => $code->reveal(), 'email' => '  '])->problems);
        self::assertEquals([new Problem('yorvoid.flow.email.invalid', field: 'email')], $this->submit($flow, 'setup_code', ['code' => $code->reveal(), 'email' => 'not an address'])->problems);
        self::assertEquals([new Problem('yorvoid.flow.setup_code.invalid', field: 'code')], $this->submit($flow, 'setup_code', ['email' => 'ana@example.com'])->problems);
        self::assertEquals([new Problem('yorvoid.flow.setup_code.invalid', field: 'code')], $this->submit($flow, 'setup_code', ['code' => '', 'email' => ''])->problems, 'The code first.');

        $claimed = $this->submit($flow, 'setup_code', ['code' => $code->reveal(), 'email' => ' ana@example.com ']);
        self::assertNull($claimed->signedIn, 'The owner gets the session only with a passkey: leaving now signs nobody in.');
        self::assertSame('passkey_registration', $claimed->step);
        $view = $claimed->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $view);
        self::assertFalse($view->canSkip, 'The owner of a fresh installation needs a passkey.');
        self::assertSame('ana@example.com', ((array) ((array) $view->jsonSerialize()['options'])['user'])['name']);

        $authenticator = new VirtualAuthenticator();
        // Requests without the session: the flow holds it.
        $context = $this->context;
        self::assertSame('passkey_registration', $this->submit($flow, 'passkey_registration', ['action' => Input::SKIP], $context)->step, 'Not skippable here.');
        $registration = $this->flows->resume($flow->state->id, $context)?->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $registration);
        $registered = $this->submit($flow, 'passkey_registration', ['response' => json_decode($authenticator->register((string) $registration->options), true), 'label' => 'Laptop'], $context);
        self::assertSame('recovery_codes', $registered->step);
        // The passkey was the last required step: now the owner gets the session.
        $owner = $registered->signedIn;
        self::assertNotNull($owner);
        self::assertSame([AuthenticationMethod::SetupCode, 'Firefox on Linux', '192.0.2.10'], [$owner->session->authentication->factors[0]->method, $owner->session->device, $owner->session->address]);
        $context = $this->context->withSession($owner->session);
        $credential = $registered->state->string('passkey_registration.credential');
        self::assertNotNull($credential);
        self::assertSame('Laptop', $this->credentials->get($this->realm, \YorvoID\Authn\Credential\CredentialId::fromString($credential))?->label);
        $codes = $registered->view;
        self::assertInstanceOf(RecoveryCodesView::class, $codes);
        self::assertCount(10, $codes->codes);
        self::assertSame(['codes' => $codes->codes], $codes->jsonSerialize());

        $unsaved = $this->submit($flow, 'recovery_codes', [], $context);
        self::assertEquals([new Problem('yorvoid.flow.recovery_codes.not_saved', field: 'saved')], $unsaved->problems);
        self::assertEquals($codes, $unsaved->view, 'The same codes, shown again.');

        $done = $this->submit($flow, 'recovery_codes', ['saved' => '1'], $context);
        self::assertSame(FlowStatus::Completed, $done->status);
        self::assertNull($done->signedIn, 'Handed over already.');
        self::assertFalse($done->state->has('recovery_codes.codes'), 'The codes are forgotten.');
        self::assertCount(1, $this->passkeys->of($this->realm, $owner->session->principalId));
        self::assertSame(10, $this->recoveryCodes->remaining($this->realm, $owner->session->principalId));
        self::assertTrue($this->setupCodes->isClaimed($this->realm));
    }

    public function testSignsInWithAPasskey(): void
    {
        [$ana, $authenticator] = $this->account();
        $flow = $this->flows->start(PasskeyFlows::SIGN_IN, $this->realm, $this->context);
        $view = $flow->view;
        self::assertInstanceOf(PasskeySignInView::class, $view);
        self::assertSame('example.com', ((array) $view->jsonSerialize()['options'])['rpId']);

        $unknown = new VirtualAuthenticator();
        $unknown->credentials[] = ['id' => random_bytes(16), 'key' => sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()), 'publicKey' => '', 'userHandle' => random_bytes(32), 'counter' => 0];
        self::assertEquals([new Problem('yorvoid.passkey.not_working', reason: 'unknown_passkey')], $this->submit($flow, 'passkey', ['response' => json_decode($unknown->signIn($this->options($flow)), true)])->problems);
        self::assertEquals([new Problem('yorvoid.passkey.not_working')], $this->submit($flow, 'passkey', ['response' => ['id' => 'x']])->problems, 'Other refusals don\'t say why.');
        self::assertEquals([new Problem('yorvoid.passkey.not_working')], $this->submit($flow, 'passkey', [])->problems);

        $done = $this->submit($flow, 'passkey', ['response' => json_decode($authenticator->signIn($this->options($flow)), true)]);
        self::assertSame(FlowStatus::Completed, $done->status);
        self::assertTrue($done->signedIn?->session->principalId->equals($ana->id));
        self::assertSame([AuthenticationMethod::Passkey, 'Firefox on Linux', '192.0.2.10'], [$done->signedIn->session->authentication->factors[0]->method, $done->signedIn->session->device, $done->signedIn->session->address]);

        Permits::call($this->principals->suspend(...), new SuspendPrincipal($this->realm, $ana->id), new Origin(OriginKind::Console));
        $again = $this->flows->start(PasskeyFlows::SIGN_IN, $this->realm, $this->context);
        self::assertEquals([new Problem('yorvoid.passkey.not_working')], $this->submit($again, 'passkey', ['response' => json_decode($authenticator->signIn($this->options($again)), true)])->problems);
    }

    public function testAcceptsAnInvitation(): void
    {
        $invitation = Permits::call($this->invitations->invite(...), new Invite($this->realm, 'Bo@Example.com'), new Origin(OriginKind::Web));
        $flow = $this->flows->start(PasskeyFlows::INVITATION, $this->realm, $this->context, [InvitationStep::TOKEN => $invitation->token->secret->reveal()]);
        self::assertEquals(new InvitationView('Bo@Example.com'), $flow->view);
        self::assertSame(['email' => 'Bo@Example.com'], $flow->view?->jsonSerialize());

        $accepted = $this->submit($flow, 'invitation', []);
        self::assertNull($accepted->signedIn, 'The invitee gets the session with a passkey.');
        self::assertSame('passkey_registration', $accepted->step);
        self::assertFalse($accepted->state->has(InvitationStep::TOKEN), 'The token is forgotten.');
        $view = $accepted->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $view);
        self::assertFalse($view->canSkip);
        $registered = $this->submit($flow, 'passkey_registration', ['response' => json_decode(new VirtualAuthenticator()->register((string) $view->options), true)]);
        self::assertSame(AuthenticationMethod::EmailLink, $registered->signedIn?->session->authentication->factors[0]->method);
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$registered->signedIn->session->device, $registered->signedIn->session->address]);

        $gone = $this->flows->start(PasskeyFlows::INVITATION, $this->realm, $this->context, [InvitationStep::TOKEN => $invitation->token->secret->reveal()]);
        self::assertEquals(new InvitationView(null), $gone->view);
        $failed = $this->submit($gone, 'invitation', []);
        self::assertSame(FlowStatus::Failed, $failed->status);
        self::assertEquals(new Problem('yorvoid.flow.invitation.not_working'), $failed->failure);

        foreach ([[], [InvitationStep::TOKEN => '']] as $data) {
            $none = $this->flows->start(PasskeyFlows::INVITATION, $this->realm, $this->context, $data);
            self::assertEquals(new InvitationView(null), $none->view);
            self::assertSame(FlowStatus::Failed, $this->submit($none, 'invitation', [])->status);
        }
    }

    public function testRecoversWithACodeAndMayAddAPasskeyLater(): void
    {
        [$ana, , $codes] = $this->account();
        $flow = $this->flows->start(PasskeyFlows::RECOVER_WITH_CODE, $this->realm, $this->context);
        self::assertSame(['holds' => null], $flow->view?->jsonSerialize(), 'No cool-down: nothing held to say.');

        $invalid = [new Problem('yorvoid.flow.recovery_code.invalid', field: 'code')];
        self::assertEquals($invalid, $this->submit($flow, 'recovery_code', ['email' => 'ana@example.com', 'code' => 'wrong'])->problems);
        self::assertEquals($invalid, $this->submit($flow, 'recovery_code', ['email' => 'nobody@example.com', 'code' => $codes[0]])->problems, 'The same whether the account exists.');
        self::assertEquals($invalid, $this->submit($flow, 'recovery_code', ['email' => 'ana@example.com'])->problems);
        self::assertEquals($invalid, $this->submit($flow, 'recovery_code', ['code' => $codes[0]])->problems);
        self::assertEquals($invalid, $this->submit($flow, 'recovery_code', ['email' => 'ana@example.com', 'code' => ''])->problems);

        $recovered = $this->submit($flow, 'recovery_code', ['email' => 'ana@example.com', 'code' => $codes[0]]);
        self::assertTrue($recovered->signedIn?->session->principalId->equals($ana->id));
        self::assertSame([AuthenticationMethod::RecoveryCode, 'Firefox on Linux'], [$recovered->signedIn->session->authentication->factors[0]->method, $recovered->signedIn->session->device]);
        $view = $recovered->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $view);
        self::assertTrue($view->canSkip);
        self::assertTrue($view->jsonSerialize()['can_skip']);

        $later = $this->submit($flow, 'passkey_registration', ['action' => Input::SKIP], $this->context->withSession($recovered->signedIn->session));
        self::assertSame(FlowStatus::Completed, $later->status, 'Nine codes are left: no new ones.');

        Permits::call($this->principals->suspend(...), new SuspendPrincipal($this->realm, $ana->id), new Origin(OriginKind::Console));
        $suspended = $this->flows->start(PasskeyFlows::RECOVER_WITH_CODE, $this->realm, $this->context);
        self::assertEquals([new Problem('yorvoid.passkey.not_working')], $this->submit($suspended, 'recovery_code', ['email' => 'ana@example.com', 'code' => $codes[1]])->problems);
    }

    public function testRecoversWithALinkAndAddsANewPasskey(): void
    {
        [$ana] = $this->account();
        $link = Permits::call($this->recoveryLinks->issue(...), new IssueRecoveryLink($this->realm, $ana->id), new Origin(OriginKind::Console));
        $flow = $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $link->secret->reveal()]);
        self::assertSame(['account' => 'ana@example.com', 'holds' => null], $flow->view?->jsonSerialize(), 'The page names whose account the link opens (login CSRF).');
        self::assertSame(['account' => null, 'holds' => null], $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => 'wrong'])->view?->jsonSerialize());
        $both = Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, email: 'bo@example.com', username: 'bo'), new Origin(OriginKind::Console));
        $bos = Permits::call($this->recoveryLinks->issue(...), new IssueRecoveryLink($this->realm, $both->id), new Origin(OriginKind::Console));
        self::assertSame(['account' => 'bo@example.com', 'holds' => null], $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $bos->secret->reveal()])->view?->jsonSerialize(), 'The email address first.');
        $named = Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, username: 'cy'), new Origin(OriginKind::Console));
        $cys = Permits::call($this->recoveryLinks->issue(...), new IssueRecoveryLink($this->realm, $named->id), new Origin(OriginKind::Console));
        self::assertSame(['account' => 'cy', 'holds' => null], $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $cys->secret->reveal()])->view?->jsonSerialize());

        $recovered = $this->submit($flow, 'recovery_link', []);
        self::assertSame([AuthenticationMethod::RecoveryLink, 'passkey_registration'], [$recovered->signedIn?->session->authentication->factors[0]->method, $recovered->step]);
        self::assertFalse($recovered->state->has(RecoveryLinkStep::TOKEN));
        self::assertNotNull($recovered->signedIn);
        $context = $this->context->withSession($recovered->signedIn->session);
        $view = $recovered->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $view);
        $done = $this->submit($flow, 'passkey_registration', ['response' => json_decode(new VirtualAuthenticator()->register((string) $view->options), true)], $context);
        self::assertSame(FlowStatus::Completed, $done->status);
        self::assertCount(2, $this->passkeys->of($this->realm, $ana->id));

        $used = $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $link->secret->reveal()]);
        self::assertEquals(new Problem('yorvoid.flow.recovery_link.not_working'), $this->submit($used, 'recovery_link', [])->failure);
        self::assertEquals(new Problem('yorvoid.flow.recovery_link.not_working'), $this->submit($this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context), 'recovery_link', [])->failure);
        self::assertEquals(new Problem('yorvoid.flow.recovery_link.not_working'), $this->submit($this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => '']), 'recovery_link', [])->failure);

        $another = Permits::call($this->recoveryLinks->issue(...), new IssueRecoveryLink($this->realm, $ana->id), new Origin(OriginKind::Console));
        Permits::call($this->principals->suspend(...), new SuspendPrincipal($this->realm, $ana->id), new Origin(OriginKind::Console));
        $suspended = $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $another->secret->reveal()]);
        self::assertEquals(new Problem('yorvoid.flow.recovery_link.not_working'), $this->submit($suspended, 'recovery_link', [])->failure, 'Suspending a principal stops its links.');
    }

    public function testAddingAPasskeyStepsUpWhenTheProofIsntRecent(): void
    {
        [$ana, $authenticator] = $this->account();
        $session = $this->session($ana);
        $this->clock->sleep(600);
        $context = $this->context->withSession($session);

        $flow = $this->flows->start(PasskeyFlows::ADD_PASSKEY, $this->realm, $context);
        self::assertSame('step_up', $flow->step);
        $view = $flow->view;
        self::assertInstanceOf(StepUpView::class, $view);
        self::assertSame('yorvoid.credential.add', $view->operation);
        self::assertEquals(['options' => json_decode((string) $view->options), 'operation' => 'yorvoid.credential.add'], $view->jsonSerialize());
        $named = $this->flows->start(PasskeyFlows::ADD_PASSKEY, $this->realm, $context, ['step_up.operation' => 'acme.passkey.add']);
        self::assertSame('acme.passkey.add', $named->view?->jsonSerialize()['operation'], 'The flow\'s data names the operation.');

        $refused = $this->submit($flow, 'step_up', ['response' => []], $context);
        self::assertSame([['yorvoid.passkey.not_working', 'invalid_response']], array_map(static fn (Problem $problem): array => [$problem->message, $problem->reason], $refused->problems));

        $stepped = $this->submit($flow, 'step_up', ['response' => json_decode($authenticator->signIn($this->options($refused)), true)], $context);
        self::assertNotNull($stepped->renewedToken);
        self::assertSame('passkey_registration', $stepped->step, 'Registration began in the session the step-up proved.');
        $renewed = $this->sessions->validate($this->realm, $stepped->renewedToken);
        self::assertNotNull($renewed);
        $registration = $stepped->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $registration);

        self::assertSame(FlowStatus::Completed, $this->submit($flow, 'passkey_registration', ['response' => json_decode(new VirtualAuthenticator()->register((string) $registration->options), true)], $context->withSession($renewed))->status);

        $fresh = $this->flows->start(PasskeyFlows::ADD_PASSKEY, $this->realm, $context->withSession($renewed));
        self::assertSame('passkey_registration', $fresh->step, 'A recent proof is enough.');
    }

    public function testStepsUpForAnOperation(): void
    {
        [$ana, $authenticator] = $this->account();
        $context = $this->context->withSession($this->session($ana));

        self::assertSame(FlowStatus::Completed, $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $context, ['step_up.operation' => 'acme.site.remove'])->status, 'Signed in with a recovery code just now: recent enough.');

        $flow = $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $context, ['step_up.operation' => 'acme.site.remove', 'step_up.multi_factor' => true]);
        self::assertEquals(new StepUpView($this->options($flow), 'acme.site.remove'), $flow->view);
        self::assertSame('required', ((array) json_decode($this->options($flow), true))['userVerification']);
        self::assertNotNull($this->submit($flow, 'step_up', ['response' => json_decode($authenticator->signIn($this->options($flow)), true)], $context)->renewedToken);

        $this->clock->sleep(120);
        self::assertSame(FlowStatus::Completed, $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $context, ['step_up.operation' => 'acme.site.remove', 'step_up.within' => 600])->status, 'No longer than the window.');
        self::assertSame('step_up', $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $context, ['step_up.operation' => 'acme.site.remove', 'step_up.within' => 60])->step, 'A route that asks for a fresher proof gets one (no redirect loop).');
        foreach ([0, -60, '60', null] as $ignored) {
            self::assertSame(FlowStatus::Completed, $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $context, ['step_up.operation' => 'acme.site.remove', 'step_up.within' => $ignored])->status, 'Only a number of seconds counts: '.var_export($ignored, true));
        }

        $signedOut = $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $this->context, ['step_up.operation' => 'acme.site.remove']);
        self::assertSame('step_up', $signedOut->step, 'Without a session there\'s no proof: the step stays.');
        self::assertEquals(new StepUpView(null, 'acme.site.remove'), $signedOut->view);
        self::assertEquals(new Problem('yorvoid.flow.signed_out'), $this->submit($signedOut, 'step_up', [])->failure);

        $registering = $this->flows->start(PasskeyFlows::ADD_PASSKEY, $this->realm, $this->context);
        self::assertEquals(new Problem('yorvoid.flow.signed_out'), $this->submit($registering, 'step_up', [])->failure);
    }

    public function testAProofThatLapsesAtTheAuthenticatorAsksForAnotherStepUp(): void
    {
        [$ana, $authenticator] = $this->account();
        $session = $this->session($ana);
        $this->clock->sleep(240);
        $context = $this->context->withSession($session);
        $flow = $this->flows->start(PasskeyFlows::ADD_PASSKEY, $this->realm, $context);
        self::assertSame('passkey_registration', $flow->step, 'Four minutes is recent enough.');

        // Two minutes at the authenticator: the ceremony still works, the proof lapsed.
        $this->clock->sleep(120);
        $lapsed = $this->submit($flow, 'passkey_registration', ['response' => json_decode(new VirtualAuthenticator()->register($this->options($flow)), true)], $context);

        self::assertEquals([FlowStatus::InProgress, 'step_up', [new Problem('yorvoid.flow.confirm_again')]], [$lapsed->status, $lapsed->step, $lapsed->problems]);
        self::assertCount(1, $this->passkeys->of($this->realm, $ana->id), 'A passkey was added without a recent proof.');
        $again = $this->submit($flow, 'step_up', ['response' => json_decode($authenticator->signIn($this->options($lapsed)), true)], $context);
        self::assertNotNull($again->renewedToken);
        $renewed = $this->sessions->validate($this->realm, $again->renewedToken);
        self::assertNotNull($renewed);
        self::assertSame('passkey_registration', $again->step);
        self::assertSame(FlowStatus::Completed, $this->submit($flow, 'passkey_registration', ['response' => json_decode(new VirtualAuthenticator()->register($this->options($again)), true)], $context->withSession($renewed))->status);
        self::assertCount(2, $this->passkeys->of($this->realm, $ana->id));
    }

    public function testAStepUpWithoutAPasskeyEndsTheFlow(): void
    {
        $bob = Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, email: 'bob@example.com'), new Origin(OriginKind::Console));
        $context = $this->context->withSession($this->session($bob));

        $flow = $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $context, ['step_up.operation' => 'acme.site.remove', 'step_up.multi_factor' => true]);

        self::assertSame(FlowStatus::Failed, $flow->status);
        self::assertStringStartsWith('yorvoid.passkey.', (string) $flow->failure?->message);
    }

    public function testAfterACodeTheFlowSaysItsUsedAndHowManyAreLeft(): void
    {
        [, , $codes] = $this->account();
        $state = new FlowState('id', PasskeyFlows::RECOVER_WITH_CODE, $this->realm, $this->clock->now()->modify('+30 minutes'));
        $now = new RecoveryCodeStep($this->operations);
        $held = new RecoveryCodeStep($this->operations, Duration::hours(24));
        $use = static fn (RecoveryCodeStep $step, string $code, string $email = 'ana@example.com'): ?Notice => $step->submit($state, new FlowContext(new Origin(OriginKind::Web)), new Input(['email' => $email, 'code' => $code]))->notice;

        self::assertEquals(new Notice('yorvoid.flow.recovery_code.used', ['remaining' => 9, 'hint' => 'none']), $use($now, $codes[0]));
        foreach (\array_slice($codes, 1, 5) as $code) {
            $use($now, $code);
        }
        self::assertEquals(new Notice('yorvoid.flow.recovery_code.used', ['remaining' => 3, 'hint' => 'none']), $use($now, $codes[6]), 'Three is enough.');
        self::assertEquals(new Notice('yorvoid.flow.recovery_code.used', ['remaining' => 2, 'hint' => 'new_set']), $use($now, $codes[7]), 'Fewer: make a new set.');
        self::assertEquals(new Notice('yorvoid.flow.recovery_code.used', ['remaining' => 1, 'hint' => 'after_wait']), $use($held, $codes[8]), 'During a cool-down, a new set waits too.');
        self::assertEquals(new Notice('yorvoid.flow.recovery_code.used', ['remaining' => 0, 'hint' => 'after_wait']), $use($held, $codes[9]), 'The last, during a cool-down: said, since no new set comes next.');
        self::assertNull($use($now, $codes[9]), 'A used code: refused, and nothing to say.');

        [, , $more] = $this->account('bo@example.com');
        foreach (\array_slice($more, 0, 8) as $code) {
            $use($now, $code, 'bo@example.com');
        }
        self::assertEquals(new Notice('yorvoid.flow.recovery_code.used', ['remaining' => 1, 'hint' => 'new_set']), $use($now, $more[8], 'bo@example.com'));
        self::assertNull($use($now, $more[9], 'bo@example.com'), 'The last, with nothing holding a new set: RecoveryCodesStep shows one next.');
    }

    public function testTheRecoveryPagesSayWhatTheCoolDownHolds(): void
    {
        $stepUp = new StepUp($this->sessions, $this->stack->coolDowns, new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock), $this->clock, Duration::hours(24));
        $flows = new Flows(new PasskeyFlows($this->operations, $this->passkeys, $this->principals, $this->invitations, $this->recoveryCodes, $this->recoveryLinks, $stepUp, Duration::minutes(5))->all(), new InMemoryFlowStore(), $this->sessions, clock: $this->clock);
        [$ana] = $this->account();
        $link = Permits::call($this->recoveryLinks->issue(...), new IssueRecoveryLink($this->realm, $ana->id), new Origin(OriginKind::Console));

        self::assertSame(['holds' => 86400], $flows->start(PasskeyFlows::RECOVER_WITH_CODE, $this->realm, $this->context)->view?->jsonSerialize());
        self::assertSame(['account' => 'ana@example.com', 'holds' => 86400], $flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $link->secret->reveal()])->view?->jsonSerialize());
    }

    public function testNoNewRecoveryCodesWhileTheCoolDownAfterARecoveryLasts(): void
    {
        $stepUp = new StepUp($this->sessions, $this->stack->coolDowns, new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock), $this->clock, Duration::hours(24));
        $bob = Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, email: 'bob@example.com'), new Origin(OriginKind::Console));
        $context = $this->context->withSession($this->session($bob));
        $state = new FlowState('id', PasskeyFlows::RECOVER_WITH_CODE, $this->realm, $this->clock->now()->modify('+30 minutes'));
        $step = new RecoveryCodesStep($this->operations, $this->recoveryCodes, $stepUp);
        self::assertTrue($step->applies($state, $context));

        $stepUp->startCoolDown($this->realm, $bob->id, new Origin(OriginKind::Web));

        self::assertFalse($step->applies($state, $context), 'New codes would replace the owner\'s during the cool-down.');
        self::assertTrue(new RecoveryCodesStep($this->operations, $this->recoveryCodes)->applies($state, $context), 'Without StepUp there\'s no cool-down to wait out.');
        $this->clock->sleep(86400);
        self::assertTrue($step->applies($state, $context));
    }

    public function testTheJourneysRequireWhatMakesThemSafe(): void
    {
        $required = [];

        foreach ($this->journeys->all() as $flow) {
            $required[$flow->name] = $flow->required;
        }

        self::assertSame([
            'yorvoid.sign_in' => ['passkey'],
            'yorvoid.setup' => ['setup_code', 'passkey_registration'],
            'yorvoid.invitation' => ['invitation', 'passkey_registration'],
            'yorvoid.recover_with_code' => ['recovery_code'],
            'yorvoid.recover_with_link' => ['recovery_link'],
            'yorvoid.add_passkey' => ['step_up', 'passkey_registration'],
            'yorvoid.step_up' => ['step_up'],
            'yorvoid.verify_email' => ['verify_email'],
        ], $required);
        self::assertNotContains(PasskeyFlows::VERIFY_EMAIL, array_map(static fn ($flow): string => $flow->name, new PasskeyFlows($this->operations, $this->passkeys, $this->principals, $this->invitations, $this->recoveryCodes, $this->recoveryLinks, new StepUp($this->sessions, $this->stack('memory')->coolDowns, new SecurityLog($this->stack('memory')->log, $this->stack('memory')->transactions)), Duration::minutes(5))->all()), 'Without EmailVerification, nothing to verify with.');
    }

    public function testRefusalsHaveMessagesToTranslate(): void
    {
        $ids = [];

        foreach (Refusal::cases() as $refusal) {
            $ids[$refusal->value] = $refusal->messageId();
        }

        self::assertSame([
            'ceremony_expired' => 'yorvoid.passkey.expired',
            'invalid_response' => 'yorvoid.passkey.not_working',
            'user_not_verified' => 'yorvoid.passkey.not_verified',
            'already_registered' => 'yorvoid.passkey.already_registered',
            'unknown_passkey' => 'yorvoid.passkey.not_working',
            'passkey_disabled' => 'yorvoid.passkey.not_working',
            'suspected_copy' => 'yorvoid.passkey.not_working',
            'principal_inactive' => 'yorvoid.passkey.not_working',
            'no_passkey' => 'yorvoid.passkey.no_passkey',
        ], $ids);
    }

    /**
     * A principal with a passkey and recovery codes.
     *
     * @return array{Principal, VirtualAuthenticator, list<string>}
     */
    private function account(string $email = 'ana@example.com'): array
    {
        $ana = Permits::call($this->principals->create(...), new CreatePrincipal($this->realm, PrincipalKind::Human, email: $email), new Origin(OriginKind::Console));
        $session = $this->session($ana);
        $authenticator = new VirtualAuthenticator();
        $ceremony = $this->passkeys->beginRegistration($session);
        Permits::call($this->passkeys->finishRegistration(...), new RegisterPasskey($session->realm, $ceremony->id, $authenticator->register($ceremony->options), 'Phone'), new Origin(OriginKind::Web), $session);
        $codes = Permits::call($this->recoveryCodes->generate(...), new \YorvoID\Authn\Recovery\GenerateRecoveryCodes($this->realm), new Origin(OriginKind::Web), $session);

        return [$ana, $authenticator, array_map(static fn (Secret $code): string => $code->reveal(), $codes)];
    }

    private function session(Principal $principal): Session
    {
        return Permits::call($this->sessions->start(...), new StartSession($this->realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryCode, $this->clock->now()))), new Origin(OriginKind::Web))->session;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function submit(Progress $flow, string $step, array $input, ?FlowContext $context = null): Progress
    {
        $progress = $this->flows->submit($flow->state->id, $step, new Input($input), $context ?? $this->context);
        self::assertNotNull($progress);

        return $progress;
    }

    /**
     * The current ceremony's options.
     */
    private function options(Progress $flow): string
    {
        $options = $this->flows->resume($flow->state->id, $this->context)?->state->string($flow->step.'.options') ?? $flow->state->string($flow->step.'.options');
        self::assertNotNull($options);

        return $options;
    }
}
