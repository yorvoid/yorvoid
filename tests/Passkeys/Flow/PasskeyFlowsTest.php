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
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\Flows;
use YorvoID\Flows\FlowStatus;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Progress;
use YorvoID\Flows\Storage\InMemoryFlowStore;
use YorvoID\Passkeys\Flow\IntentConfirmationStep;
use YorvoID\Passkeys\Flow\InvitationStep;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Flow\RecoveryLinkStep;
use YorvoID\Passkeys\Flow\View\IntentConfirmationView;
use YorvoID\Passkeys\Flow\View\InvitationView;
use YorvoID\Passkeys\Flow\View\PasskeyRegistrationView;
use YorvoID\Passkeys\Flow\View\PasskeySignInView;
use YorvoID\Passkeys\Flow\View\RecoveryCodesView;
use YorvoID\Passkeys\Flow\View\StepUpView;
use YorvoID\Passkeys\Intent\IntentSummary;
use YorvoID\Passkeys\Intent\SigningRequest;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Refusal;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Throttle\Throttle;
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

    protected function setUp(): void
    {
        $stack = $this->stack('memory');
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
        $this->recoveryCodes = new RecoveryCodes($stack->recoveryCodes, $credentials, $this->sessions, $stepUp, $attempts, $log, $stack->transactions, clock: $this->clock);
        $tokens = new OneTimeTokens($stack->tokens, $this->principals, $log, $stack->transactions, clock: $this->clock);
        $this->recoveryLinks = new RecoveryLinks($tokens, $this->sessions, $this->principals, $stepUp, $stack->transactions, $this->clock);
        $this->setupCodes = new SetupCodes($stack->setup, $this->principals, $log, $stack->transactions, $attempts, $this->clock);
        $this->invitations = new Invitations($this->principals, $tokens, $stack->transactions, new Throttle($stack->throttle, $this->clock));
        $this->journeys = $journeys = new PasskeyFlows($this->passkeys, $this->sessions, $this->principals, $this->setupCodes, $this->invitations, $this->recoveryCodes, $this->recoveryLinks, $stepUp, $attempts, Duration::minutes(5), $this->clock);
        $this->flows = new Flows($journeys->all(), new InMemoryFlowStore(), $this->sessions, clock: $this->clock);
        $this->context = new FlowContext(new Origin(OriginKind::Web), device: 'Firefox on Linux', address: '192.0.2.10');
    }

    public function testClaimsAFreshInstallation(): void
    {
        $code = $this->setupCodes->create(new CreateSetupCode($this->realm, Duration::days(1)), new Origin(OriginKind::Console));
        $flow = $this->flows->start(PasskeyFlows::SETUP, $this->realm, $this->context);
        self::assertSame('setup_code', $flow->step);
        self::assertSame([], $flow->view?->jsonSerialize());

        self::assertEquals([new Problem('yorvoid.flow.setup_code.invalid', field: 'code')], $this->submit($flow, 'setup_code', ['code' => 'WRONG-CODE', 'email' => 'ana@example.com'])->problems);
        self::assertEquals([new Problem('yorvoid.flow.email.invalid', field: 'email')], $this->submit($flow, 'setup_code', ['code' => $code->reveal(), 'email' => '  '])->problems);
        self::assertEquals([new Problem('yorvoid.flow.email.invalid', field: 'email')], $this->submit($flow, 'setup_code', ['code' => $code->reveal(), 'email' => 'not an address'])->problems);
        self::assertEquals([new Problem('yorvoid.flow.setup_code.invalid', field: 'code')], $this->submit($flow, 'setup_code', ['email' => 'ana@example.com'])->problems);
        self::assertEquals([new Problem('yorvoid.flow.setup_code.invalid', field: 'code')], $this->submit($flow, 'setup_code', ['code' => '', 'email' => ''])->problems, 'The code first.');

        $claimed = $this->submit($flow, 'setup_code', ['code' => $code->reveal(), 'email' => ' ana@example.com ']);
        $owner = $claimed->signedIn;
        self::assertNotNull($owner);
        self::assertSame([AuthenticationMethod::SetupCode, 'Firefox on Linux', '192.0.2.10'], [$owner->session->authentication->factors[0]->method, $owner->session->device, $owner->session->address]);
        self::assertSame('passkey_registration', $claimed->step);
        $view = $claimed->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $view);
        self::assertFalse($view->canSkip, 'The owner of a fresh installation needs a passkey.');
        self::assertSame('ana@example.com', ((array) ((array) $view->jsonSerialize()['options'])['user'])['name']);

        $authenticator = new VirtualAuthenticator();
        $context = $this->context->withSession($owner->session);
        self::assertSame('passkey_registration', $this->submit($flow, 'passkey_registration', ['action' => Input::SKIP], $context)->step, 'Not skippable here.');
        $registration = $this->flows->resume($flow->state->id, $context)?->view;
        self::assertInstanceOf(PasskeyRegistrationView::class, $registration);
        $registered = $this->submit($flow, 'passkey_registration', ['response' => json_decode($authenticator->register((string) $registration->options), true), 'label' => 'Laptop'], $context);
        self::assertSame('recovery_codes', $registered->step);
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

        $this->principals->suspend(new SuspendPrincipal($this->realm, $ana->id), new Origin(OriginKind::Console));
        $again = $this->flows->start(PasskeyFlows::SIGN_IN, $this->realm, $this->context);
        self::assertEquals([new Problem('yorvoid.passkey.not_working')], $this->submit($again, 'passkey', ['response' => json_decode($authenticator->signIn($this->options($again)), true)])->problems);
    }

    public function testAcceptsAnInvitation(): void
    {
        $invitation = $this->invitations->invite(new Invite($this->realm, 'Bo@Example.com'), new Origin(OriginKind::Web));
        $flow = $this->flows->start(PasskeyFlows::INVITATION, $this->realm, $this->context, [InvitationStep::TOKEN => $invitation->token->secret->reveal()]);
        self::assertEquals(new InvitationView('Bo@Example.com'), $flow->view);
        self::assertSame(['email' => 'Bo@Example.com'], $flow->view?->jsonSerialize());

        $accepted = $this->submit($flow, 'invitation', []);
        self::assertSame(AuthenticationMethod::EmailLink, $accepted->signedIn?->session->authentication->factors[0]->method);
        self::assertSame('passkey_registration', $accepted->step);
        self::assertFalse($accepted->state->has(InvitationStep::TOKEN), 'The token is forgotten.');
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$accepted->signedIn->session->device, $accepted->signedIn->session->address]);

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
        self::assertSame([], $flow->view?->jsonSerialize());

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

        $this->principals->suspend(new SuspendPrincipal($this->realm, $ana->id), new Origin(OriginKind::Console));
        $suspended = $this->flows->start(PasskeyFlows::RECOVER_WITH_CODE, $this->realm, $this->context);
        self::assertEquals([new Problem('yorvoid.passkey.not_working')], $this->submit($suspended, 'recovery_code', ['email' => 'ana@example.com', 'code' => $codes[1]])->problems);
    }

    public function testRecoversWithALinkAndAddsANewPasskey(): void
    {
        [$ana] = $this->account();
        $link = $this->recoveryLinks->issue(new IssueRecoveryLink($this->realm, $ana->id), new Origin(OriginKind::Console));
        $flow = $this->flows->start(PasskeyFlows::RECOVER_WITH_LINK, $this->realm, $this->context, [RecoveryLinkStep::TOKEN => $link->secret->reveal()]);
        self::assertSame([], $flow->view?->jsonSerialize());

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

        $another = $this->recoveryLinks->issue(new IssueRecoveryLink($this->realm, $ana->id), new Origin(OriginKind::Console));
        $this->principals->suspend(new SuspendPrincipal($this->realm, $ana->id), new Origin(OriginKind::Console));
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

        $signedOut = $this->flows->start(PasskeyFlows::STEP_UP, $this->realm, $this->context, ['step_up.operation' => 'acme.site.remove']);
        self::assertSame('step_up', $signedOut->step, 'Without a session there\'s no proof: the step stays.');
        self::assertEquals(new StepUpView(null, 'acme.site.remove'), $signedOut->view);
        self::assertEquals(new Problem('yorvoid.flow.signed_out'), $this->submit($signedOut, 'step_up', [])->failure);

        $registering = $this->flows->start(PasskeyFlows::ADD_PASSKEY, $this->realm, $this->context);
        self::assertEquals(new Problem('yorvoid.flow.signed_out'), $this->submit($registering, 'step_up', [])->failure);
    }

    public function testConfirmsASignedOperation(): void
    {
        [$ana, $authenticator] = $this->account();
        $context = $this->context->withSession($this->session($ana));
        $request = new SigningRequest('nonce-1', new IntentSummary('Remove the site jane', ['site' => 'jane'], new \DateTimeImmutable('2026-10-28 18:05:00 UTC')), '{"challenge":"AQID","rpId":"example.com","allowCredentials":[]}');

        $flow = $this->flows->start(PasskeyFlows::SIGN_INTENT, $this->realm, $context, IntentConfirmationStep::data($request));

        self::assertEquals(new IntentConfirmationView('Remove the site jane', ['site' => 'jane'], $request->options, '2026-10-28T18:05:00+00:00'), $flow->view);
        self::assertEquals(['summary' => 'Remove the site jane', 'parameters' => ['site' => 'jane'], 'options' => json_decode($request->options), 'expires_at' => '2026-10-28T18:05:00+00:00'], $flow->view?->jsonSerialize());
        self::assertEquals([new Problem('yorvoid.passkey.not_working', field: 'response')], $this->submit($flow, 'confirm', [], $context)->problems);

        $assertion = $authenticator->signIn($request->options);
        $done = $this->submit($flow, 'confirm', ['response' => $assertion], $context);
        self::assertSame(FlowStatus::Completed, $done->status);
        self::assertSame(['nonce-1', $assertion], [$done->state->get(IntentConfirmationStep::NONCE), $done->state->get(IntentConfirmationStep::ASSERTION)]);

        $signedOut = $this->flows->start(PasskeyFlows::SIGN_INTENT, $this->realm, $this->context, IntentConfirmationStep::data($request));
        self::assertEquals(new Problem('yorvoid.flow.signed_out'), $this->submit($signedOut, 'confirm', ['response' => $assertion])->failure);

        $broken = $this->flows->start(PasskeyFlows::SIGN_INTENT, $this->realm, $context, ['intent.parameters' => ['site' => 5, 3 => 'x']]);
        self::assertEquals(['summary' => '', 'parameters' => ['site' => ''], 'options' => new \stdClass(), 'expires_at' => ''], $broken->view?->jsonSerialize(), 'What isn\'t text is shown as nothing.');
        self::assertSame([], $this->flows->start(PasskeyFlows::SIGN_INTENT, $this->realm, $context, ['intent.parameters' => 'site'])->view?->jsonSerialize()['parameters']);
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
            'yorvoid.invitation' => ['invitation'],
            'yorvoid.recover_with_code' => ['recovery_code'],
            'yorvoid.recover_with_link' => ['recovery_link'],
            'yorvoid.add_passkey' => ['passkey_registration'],
            'yorvoid.step_up' => ['step_up'],
            'yorvoid.sign_intent' => ['confirm'],
        ], $required);
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
    private function account(): array
    {
        $ana = $this->principals->create(new CreatePrincipal($this->realm, PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Console));
        $session = $this->session($ana);
        $authenticator = new VirtualAuthenticator();
        $ceremony = $this->passkeys->beginRegistration($session);
        $this->passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Phone', new Origin(OriginKind::Web));
        $codes = $this->recoveryCodes->generate(new \YorvoID\Authn\Recovery\GenerateRecoveryCodes($this->realm), $session, new Origin(OriginKind::Web));

        return [$ana, $authenticator, array_map(static fn (Secret $code): string => $code->reveal(), $codes)];
    }

    private function session(Principal $principal): Session
    {
        return $this->sessions->start(new StartSession($this->realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryCode, $this->clock->now()))), new Origin(OriginKind::Web))->session;
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
