<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use YorvoID\Flows\Problem;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\IntentConfirmationView;
use YorvoID\Passkeys\Flow\View\InvitationView;
use YorvoID\Passkeys\Flow\View\PasskeyRegistrationView;
use YorvoID\Passkeys\Flow\View\PasskeySignInView;
use YorvoID\Passkeys\Flow\View\RecoveryCodeView;
use YorvoID\Passkeys\Flow\View\RecoveryLinkView;
use YorvoID\Passkeys\Flow\View\SetupCodeView;
use YorvoID\Passkeys\Flow\View\StepUpView;
use YorvoID\Symfony\Templates\FlowPages;
use YorvoID\Symfony\Templates\Sample;
use YorvoID\Symfony\Templates\Samples;
use YorvoID\Symfony\Templates\TemplateLint;
use YorvoID\Symfony\YorvoIDBundle;
use YorvoID\Translation\Translator;

/**
 * Each of the lint's rules, on pages written by hand (UX-014).
 */
final class TemplateLintTest extends TestCase
{
    private const string FORM = '<form method="post" action="'.Sample::ACTION.'">';

    private const string SCRIPT = '<script type="module" src="/forms.js"></script>';

    private TemplateLint $lint;

    protected function setUp(): void
    {
        $this->lint = new TemplateLint(new FlowPages(new Translator(YorvoIDBundle::TRANSLATIONS), null, '/forms.js'));
    }

    public function testEveryPageSaysItsLanguageAndHasATitleAndAHeading(): void
    {
        self::assertSame([], $this->check('recovery_link', new RecoveryLinkView(), self::FORM.'<button>Go</button></form>'));
        self::assertSame([
            'The page doesn\'t say its language: <html lang="…">.',
            'The page has no title.',
            'The page has no heading: <h1>.',
        ], $this->lint->check(new Sample('recovery_link', 'default', 'f', new RecoveryLinkView()), '<!DOCTYPE html><html lang=" "><head><title> </title></head><body>'.self::FORM.'<button>Go</button></form></body></html>'));
        self::assertSame(['The page doesn\'t say its language: <html lang="…">.'], $this->lint->check(new Sample('recovery_link', 'default', 'f', new RecoveryLinkView()), '<!DOCTYPE html><html><head><title>T</title></head><body><h1>T</h1>'.self::FORM.'<button>Go</button></form></body></html>'));
    }

    public function testEveryFieldHasALabel(): void
    {
        $labelled = '<label for="a">A</label><input id="a" name="a"><label>B <input name="b"></label><input name="c" aria-label="C"><span id="d">D</span><input name="d" aria-labelledby="d"><select id="e" name="e" aria-label="E"></select><textarea name="f" aria-label="F"></textarea>'
            .'<input type="hidden" name="h"><input type="submit"><input type="button">';
        self::assertSame([], $this->check('recovery_link', new RecoveryLinkView(), self::FORM.$labelled.'<button>Go</button></form>'));

        self::assertSame([
            'The field "named" has no label.',
            'The field "only-id" has no label.',
            'The field "?" has no label.',
            'The field "blank" has no label.',
            'The field "blank-by" has no label.',
            'The field "list" has no label.',
            'The field "text" has no label.',
            'The field "other" has no label.',
        ], $this->check('recovery_link', new RecoveryLinkView(), self::FORM.'<input id="x" name="named"><input id="only-id"><input><input name="blank" aria-label=" "><input name="blank-by" aria-labelledby=" "><select name="list"></select><textarea name="text"></textarea><label for="elsewhere">L</label><input id="y" name="other"><button>Go</button></form>'));
    }

    public function testFormsPostToTheirStep(): void
    {
        self::assertSame([], $this->check('recovery_link', new RecoveryLinkView(), '<form method="POST" action="'.Sample::ACTION.'"><button>Go</button></form>'));
        self::assertSame([
            'A form doesn\'t post: method="post".',
            'A form doesn\'t post to its step: action="{{ action }}".',
            'A form doesn\'t post: method="post".',
        ], $this->check('recovery_link', new RecoveryLinkView(), '<form action="/elsewhere"><button>Go</button></form><form method="get" action="'.Sample::ACTION.'"></form>'));
    }

    public function testProblemsAreAnnouncedAndOnTheirFields(): void
    {
        $problems = [new Problem('yorvoid.flow.recovery_code.invalid', field: 'code'), new Problem('yorvoid.flow.signed_out')];
        $message = 'That code didn\'t work. Check it, or use another one.';
        $fields = '<label for="e">E</label><input id="e" name="email">';
        $good = '<div role="alert"><p>'.$message.'</p></div><p role="alert">You were signed out. Sign in, then try again.</p>'.self::FORM.$fields.'<label for="c">C</label><input id="c" name="code" aria-invalid="true" aria-describedby="  hint   problem "><span id="hint">Five groups</span><strong id="problem">'.$message.'</strong></form>';

        self::assertSame([], $this->check('recovery_code', new RecoveryCodeView(), $good, $problems));
        self::assertSame([
            'The problem "'.$message.'" isn\'t in an alert: role="alert".',
            'The field "code" doesn\'t say it\'s invalid: aria-invalid="true".',
            'The field "code" doesn\'t say why it\'s invalid: aria-describedby, to its problem.',
            'The problem "You were signed out. Sign in, then try again." isn\'t in an alert: role="alert".',
        ], $this->check('recovery_code', new RecoveryCodeView(), '<p>'.$message.'</p>'.self::FORM.$fields.'<label for="c">C</label><input id="c" name="code" aria-describedby="nowhere"></form>', $problems));
        self::assertSame([
            'There\'s no field "code" for its problem.',
            'There\'s no recovery code field: name="code".',
        ], $this->check('recovery_code', new RecoveryCodeView(), '<div role="alert">'.$message.'</div>'.self::FORM.$fields.'</form>', [$problems[0]]));
    }

    public function testThePasskeyPagesRunTheirCeremony(): void
    {
        $email = '<label for="u">Email</label><input id="u" name="username" autocomplete="username webauthn">';

        self::assertSame([], $this->check('passkey', new PasskeySignInView('{}'), self::ceremony('get').$email.'</form>'.self::SCRIPT));
        self::assertSame([
            'There\'s no passkey form: <form data-yorvoid-ceremony="get" data-yorvoid-options="{{ view.options }}">.',
            'The email field doesn\'t offer passkeys: autocomplete="username webauthn".',
        ], $this->check('passkey', new PasskeySignInView('{}'), self::ceremony('create').'<label for="u">Email</label><input id="u" name="username" autocomplete="username"></form>'.self::SCRIPT));
        self::assertSame([
            'The passkey form doesn\'t have the ceremony\'s options: data-yorvoid-options="{{ view.options }}".',
            'The passkey form has no field for the answer: <input type="hidden" name="response">.',
            'The passkey form has nowhere to say what went wrong: an element with data-yorvoid-error.',
            'The page doesn\'t load the browser client\'s script: <script type="module" src="{{ script }}">.',
        ], $this->check('passkey', new PasskeySignInView('{}'), '<form method="post" action="'.Sample::ACTION.'" data-yorvoid-ceremony="get" data-yorvoid-options="{nope">'.$email.'</form><script src="/forms.js"></script>'));

        self::assertSame([], $this->check('step_up', new StepUpView('{}', null), self::ceremony('get').'</form>'.self::SCRIPT));
        self::assertSame(['There\'s no passkey form: <form data-yorvoid-ceremony="get" data-yorvoid-options="{{ view.options }}">.'], $this->check('step_up', new StepUpView('{}', null), '<p>Confirm</p>'));
        self::assertSame([], $this->check('step_up', new StepUpView(null, null), '<p>Signed out</p>'), 'Signed out, there\'s no ceremony.');

        self::assertSame([], $this->check('passkey_registration', new PasskeyRegistrationView('{}', false), self::ceremony('create').'</form>'.self::SCRIPT));
        self::assertSame(['There\'s no passkey form: <form data-yorvoid-ceremony="create" data-yorvoid-options="{{ view.options }}">.'], $this->check('passkey_registration', new PasskeyRegistrationView('{}', false), self::ceremony('get').'</form>'.self::SCRIPT));
        self::assertSame(['There\'s no way to add a passkey later: a button name="action" value="skip".'], $this->check('passkey_registration', new PasskeyRegistrationView('{}', true), self::ceremony('create').'<button name="action" value="later">Later</button></form>'.self::SCRIPT));
        self::assertSame([], $this->check('passkey_registration', new PasskeyRegistrationView('{}', true), self::ceremony('create').'<button name="action" value="skip">Not now</button></form>'.self::SCRIPT));
        self::assertSame([], $this->check('passkey_registration', new PasskeyRegistrationView(null, true), '<p>Signed out</p>'));
    }

    public function testTheSigningPageShowsEverythingSigned(): void
    {
        $view = new IntentConfirmationView('Remove the site jane.example', ['site' => 'jane.example', 'keep backups' => 'no'], '{}', '');

        self::assertSame([], $this->check('confirm', $view, '<p>Remove the site jane.example</p><dl><dt>site</dt><dd>jane.example</dd><dt>keep backups</dt><dd>no</dd></dl>'.self::ceremony('get').'</form>'.self::SCRIPT));
        self::assertSame([
            'There\'s no passkey form: <form data-yorvoid-ceremony="get" data-yorvoid-options="{{ view.options }}">.',
            'The page doesn\'t show what is signed in full: "Remove the site jane.example" is missing.',
            'The page doesn\'t show what is signed in full: "jane.example" is missing.',
            'The page doesn\'t show what is signed in full: "no" is missing.',
        ], $this->check('confirm', $view, '<p>Remove the site jane-example</p><p>site: jane-example, keep backups: nothing</p>'));
        self::assertSame(
            [],
            $this->check('confirm', self::view(['summary' => '', 'parameters' => [3 => 7, 'site' => ' jane ']]), '<p>site: jane.</p>'.self::ceremony('get').'</form>'.self::SCRIPT),
            'What isn\'t text has nothing to show; space around a value doesn\'t count.',
        );
    }

    public function testTheOtherStepsHaveTheirFields(): void
    {
        $setup = '<label for="c">C</label><input id="c" name="code" autocomplete="one-time-code"><label for="e">E</label><input id="e" name="email" type="email">';
        self::assertSame([], $this->check('setup_code', new SetupCodeView(), self::FORM.$setup.'</form>'));
        self::assertSame([
            'There\'s no setup code field: name="code" autocomplete="one-time-code".',
            'There\'s no email field: name="email" type="email".',
        ], $this->check('setup_code', new SetupCodeView(), self::FORM.'<label for="c">C</label><input id="c" name="code"><label for="e">E</label><input id="e" name="email"></form>'));

        self::assertSame([], $this->check('recovery_code', new RecoveryCodeView(), self::FORM.'<label for="e">E</label><input id="e" name="email"><label for="c">C</label><input id="c" name="code"></form>'));
        self::assertSame(['There\'s no email field: name="email".', 'There\'s no recovery code field: name="code".'], $this->check('recovery_code', new RecoveryCodeView(), self::FORM.'</form>'));

        $codes = self::view(['codes' => ['AAAA-1', 'BBBB-2', 5]]);
        self::assertSame([], $this->check('recovery_codes', $codes, '<ol><li>AAAA-1</li><li>BBBB-2</li></ol>'.self::FORM.'<input id="s" name="saved" type="checkbox"><label for="s">Saved</label></form>'));
        self::assertSame([
            'A recovery code isn\'t shown: "BBBB-2".',
            'There\'s no way to confirm the codes were saved: a checkbox name="saved".',
        ], $this->check('recovery_codes', $codes, '<p>AAAA-1 BBBB-22</p>'));

        self::assertSame([], $this->check('invitation', new InvitationView('ana@example.com'), '<p>You were invited as ana@example.com.</p>'.self::FORM.'<button type="submit">Accept</button></form>'));
        self::assertSame([
            'The page doesn\'t say who is invited: "ana@example.com".',
            'There\'s no way to accept: a form with a button.',
        ], $this->check('invitation', new InvitationView('ana@example.com'), self::FORM.'<button type="button">Accept</button></form>'));
        self::assertSame([], $this->check('invitation', new InvitationView(null), '<p>It doesn\'t work any more.</p>'));

        self::assertSame(['There\'s no way to continue: a form with a button.'], $this->check('recovery_link', new RecoveryLinkView(), '<button>Outside</button>'));
        self::assertSame([], $this->check('expired', null, '<p>Gone</p>'), 'Pages without a step have no step rules.');
    }

    public function testTheSamplesCoverEveryStateWithTheirData(): void
    {
        $samples = array_map(static fn (Sample $sample): array => ['template' => $sample->template, 'state' => $sample->state, 'flow' => $sample->flow, 'view' => $sample->view, 'problems' => $sample->problems], Samples::all());

        self::assertJsonStringEqualsJsonFile(__DIR__.'/expected/samples.json', (string) json_encode($samples, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        self::assertSame($samples[3], ['template' => 'setup_code', 'state' => 'problems'] + $samples[3]);
        self::assertNull(Samples::find('passkey', 'nope'));
        self::assertSame('problems', Samples::find('passkey', 'problems')?->state);
        self::assertSame('passkey_registration', Samples::find('passkey_registration', 'can_skip')?->template);
    }

    /**
     * @param list<Problem> $problems
     *
     * @return list<string>
     */
    private function check(string $template, ?StepView $view, string $body, array $problems = []): array
    {
        return $this->lint->check(new Sample($template, 'default', 'yorvoid.sample', $view, $problems), '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body><main><h1>T</h1>'.$body.'</main></body></html>');
    }

    /**
     * A view as an application's own step might give it: the lint reads only its JSON.
     *
     * @param array<string, mixed> $json
     */
    private static function view(array $json): StepView
    {
        return new readonly class($json) implements StepView {
            /**
             * @param array<string, mixed> $json
             */
            public function __construct(
                private array $json,
            ) {
            }

            public function jsonSerialize(): array
            {
                return $this->json;
            }
        };
    }

    private static function ceremony(string $kind): string
    {
        return \sprintf('<form method="post" action="%s" data-yorvoid-ceremony="%s" data-yorvoid-options="{}"><input type="hidden" name="response"><p data-yorvoid-error></p>', Sample::ACTION, $kind);
    }
}
