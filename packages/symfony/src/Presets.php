<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

/**
 * Configuration presets (PLT-090): documented starting points for kinds of applications. A preset
 * gives the settings the application leaves out; yorvoid:doctor warns where the application's are
 * weaker than its preset's.
 *
 * Only settings YorvoID has today are in them. The regulated preset's quorum intents and strict audit
 * and the agent platform's delegation limits arrive with the milestones that bring those features.
 *
 * @internal
 */
final class Presets
{
    public const string DEFAULT = 'consumer_site';

    /**
     * The settings each preset gives, as dotted names under "yorvoid:".
     */
    public const array ALL = [
        // A public site: AAL2 sessions, the limits sized for people signing in from anywhere.
        'consumer_site' => [
            'sessions.preset' => 'aal2',
            'sessions.recognise_devices' => true,
            'passkeys.user_verification' => 'required',
            'step_up.window' => '5m',
            'step_up.cool_down' => null,
            'limits.per_account_and_address' => '10/15m',
            'limits.per_account' => '100/1h',
            'limits.per_address' => '300/15m',
        ],
        // Staff in an office: working-day sessions, and many people behind one address.
        'internal_tool' => [
            'sessions.preset' => 'console',
            'sessions.recognise_devices' => true,
            'passkeys.user_verification' => 'required',
            'step_up.window' => '5m',
            'step_up.cool_down' => null,
            'limits.per_account_and_address' => '10/15m',
            'limits.per_account' => '100/1h',
            'limits.per_address' => '1000/15m',
        ],
        // Regulated data: AAL3 session limits, fresher proofs, a cool-down after recovery, tighter limits.
        'regulated' => [
            'sessions.preset' => 'aal3',
            'sessions.recognise_devices' => true,
            'passkeys.user_verification' => 'required',
            'step_up.window' => '2m',
            'step_up.cool_down' => '24h',
            'limits.per_account_and_address' => '5/15m',
            'limits.per_account' => '50/1h',
            'limits.per_address' => '300/15m',
        ],
        // People delegating to agents: a taken-over account hands its agents over too, so recovering one
        // waits a day before sensitive operations.
        'agent_platform' => [
            'sessions.preset' => 'aal2',
            'sessions.recognise_devices' => true,
            'passkeys.user_verification' => 'required',
            'step_up.window' => '5m',
            'step_up.cool_down' => '24h',
            'limits.per_account_and_address' => '10/15m',
            'limits.per_account' => '100/1h',
            'limits.per_address' => '300/15m',
        ],
    ];
}
