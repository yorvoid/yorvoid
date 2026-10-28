<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use YorvoID\Core\Realm;
use YorvoID\Flows\Flows;
use YorvoID\Passkeys\Flow\IntentConfirmationStep;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Intent\IntentSummary;
use YorvoID\Passkeys\Intent\SigningRequest;
use YorvoID\Symfony\Flow\FlowContexts;

/**
 * An application starting the signing of an operation its verifier prepared, then sending the person
 * to the confirming page.
 */
final readonly class IntentController
{
    public function __construct(
        private Flows $flows,
        private FlowContexts $contexts,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $options = $request->query->getString('options');
        $signing = new SigningRequest('nonce-1', new IntentSummary('Remove the site <jane>', ['site' => '<jane>', 'keep backups' => 'no'], new \DateTimeImmutable('2026-10-28 18:05:00 UTC')), $options);
        $progress = $this->flows->start(PasskeyFlows::SIGN_INTENT, Realm::default(), $this->contexts->of($request), IntentConfirmationStep::data($signing));

        return new RedirectResponse($this->urls->generate('yorvoid_flow_show', ['flow' => PasskeyFlows::SIGN_INTENT, 'id' => $progress->state->id]));
    }
}
