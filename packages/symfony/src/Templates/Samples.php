<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

use YorvoID\Flows\Problem;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Flow\View\IntentConfirmationView;
use YorvoID\Passkeys\Flow\View\InvitationView;
use YorvoID\Passkeys\Flow\View\PasskeyRegistrationView;
use YorvoID\Passkeys\Flow\View\PasskeySignInView;
use YorvoID\Passkeys\Flow\View\RecoveryCodesView;
use YorvoID\Passkeys\Flow\View\RecoveryCodeView;
use YorvoID\Passkeys\Flow\View\RecoveryLinkView;
use YorvoID\Passkeys\Flow\View\SetupCodeView;
use YorvoID\Passkeys\Flow\View\StepUpView;

/**
 * Every flow page YorvoID has a template for, in every state, with made-up data (UX-006).
 *
 * @api
 */
final class Samples
{
    /** The templates YorvoID ships, in "@YorvoID/flow/" */
    public const array TEMPLATES = ['layout', 'fields', 'passkey', 'setup_code', 'passkey_registration', 'recovery_codes', 'invitation', 'recovery_code', 'recovery_link', 'step_up', 'confirm', 'failed', 'expired'];

    private const string GET = '{"challenge":"c2FtcGxlLWNoYWxsZW5nZS1ub3QtZm9yLXVzZQ","rpId":"example.com","allowCredentials":[],"userVerification":"required","timeout":300000}';

    private const string CREATE = '{"challenge":"c2FtcGxlLWNoYWxsZW5nZS1ub3QtZm9yLXVzZQ","rp":{"id":"example.com","name":"example.com"},"user":{"id":"c2FtcGxl","name":"ana@example.com","displayName":"ana@example.com"},"pubKeyCredParams":[{"type":"public-key","alg":-7}],"excludeCredentials":[],"timeout":300000}';

    /**
     * @return list<Sample>
     */
    public static function all(): array
    {
        $codes = ['7KQ2M-9XHAB-C3DEF-G4HJK-MNPQ5', 'RS6TV-W7XYZ-AB8CD-EF9GH-JK0MN', 'PQ1RS-TV2WX-YZ3AB-CD4EF-GH5JK', 'MN6PQ-RS7TV-WX8YZ-AB9CD-EF0GH', 'JK1MN-PQ2RS-TV3WX-YZ4AB-CD5EF', 'GH6JK-MN7PQ-RS8TV-WX9YZ-AB0CD', 'EF1GH-JK2MN-PQ3RS-TV4WX-YZ5AB', 'CD6EF-GH7JK-MN8PQ-RS9TV-WX0YZ', 'AB1CD-EF2GH-JK3MN-PQ4RS-TV5WX', 'YZ6AB-CD7EF-GH8JK-MN9PQ-RS0TV'];
        $passkey = new Problem('yorvoid.passkey.not_working');

        return [
            new Sample('passkey', 'default', PasskeyFlows::SIGN_IN, new PasskeySignInView(self::GET)),
            new Sample('passkey', 'problems', PasskeyFlows::SIGN_IN, new PasskeySignInView(self::GET), [$passkey]),
            new Sample('setup_code', 'default', PasskeyFlows::SETUP, new SetupCodeView()),
            new Sample('setup_code', 'problems', PasskeyFlows::SETUP, new SetupCodeView(), [new Problem('yorvoid.flow.setup_code.invalid', field: 'code'), new Problem('yorvoid.flow.email.invalid', field: 'email')]),
            new Sample('passkey_registration', 'default', PasskeyFlows::SETUP, new PasskeyRegistrationView(self::CREATE, false)),
            new Sample('passkey_registration', 'can_skip', PasskeyFlows::RECOVER_WITH_CODE, new PasskeyRegistrationView(self::CREATE, true)),
            new Sample('passkey_registration', 'problems', PasskeyFlows::SETUP, new PasskeyRegistrationView(self::CREATE, false), [new Problem('yorvoid.passkey.already_registered')]),
            new Sample('passkey_registration', 'signed_out', PasskeyFlows::ADD_PASSKEY, new PasskeyRegistrationView(null, false)),
            new Sample('recovery_codes', 'default', PasskeyFlows::SETUP, new RecoveryCodesView($codes)),
            new Sample('recovery_codes', 'problems', PasskeyFlows::SETUP, new RecoveryCodesView($codes), [new Problem('yorvoid.flow.recovery_codes.not_saved', field: 'saved')]),
            new Sample('invitation', 'default', PasskeyFlows::INVITATION, new InvitationView('ana@example.com')),
            new Sample('invitation', 'not_working', PasskeyFlows::INVITATION, new InvitationView(null)),
            new Sample('recovery_code', 'default', PasskeyFlows::RECOVER_WITH_CODE, new RecoveryCodeView()),
            new Sample('recovery_code', 'problems', PasskeyFlows::RECOVER_WITH_CODE, new RecoveryCodeView(), [new Problem('yorvoid.flow.recovery_code.invalid', field: 'code')]),
            new Sample('recovery_code', 'too_many_attempts', PasskeyFlows::RECOVER_WITH_CODE, new RecoveryCodeView(), [new Problem('yorvoid.flow.too_many_attempts', ['minutes' => 15])]),
            new Sample('recovery_link', 'default', PasskeyFlows::RECOVER_WITH_LINK, new RecoveryLinkView()),
            new Sample('step_up', 'default', PasskeyFlows::STEP_UP, new StepUpView(self::GET, 'acme.site.remove')),
            new Sample('step_up', 'problems', PasskeyFlows::STEP_UP, new StepUpView(self::GET, 'acme.site.remove'), [new Problem('yorvoid.passkey.not_verified')]),
            new Sample('step_up', 'signed_out', PasskeyFlows::STEP_UP, new StepUpView(null, 'acme.site.remove')),
            new Sample('confirm', 'default', PasskeyFlows::SIGN_INTENT, new IntentConfirmationView('Remove the site jane.example', ['site' => 'jane.example', 'keep backups' => 'no'], self::GET, '2026-10-28T18:05:00+00:00')),
            new Sample('confirm', 'problems', PasskeyFlows::SIGN_INTENT, new IntentConfirmationView('Remove the site jane.example', ['site' => 'jane.example'], self::GET, '2026-10-28T18:05:00+00:00'), [$passkey]),
            new Sample('failed', 'default', PasskeyFlows::INVITATION, null, [new Problem('yorvoid.flow.invitation.not_working')]),
            new Sample('expired', 'default', PasskeyFlows::SIGN_IN, null, [new Problem('yorvoid.flow.expired')]),
        ];
    }

    public static function find(string $template, string $state): ?Sample
    {
        foreach (self::all() as $sample) {
            if ($template === $sample->template && $state === $sample->state) {
                return $sample;
            }
        }

        return null;
    }
}
