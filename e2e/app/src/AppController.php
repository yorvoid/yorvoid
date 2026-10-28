<?php

declare(strict_types=1);

namespace YorvoID\E2e;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Flows\Flows;
use YorvoID\IntentVerifier\PreparedIntent;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\Passkeys\Flow\IntentConfirmationStep;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Symfony\Flow\FlowContexts;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * The application's own pages, and endpoints only the browser tests use.
 */
final readonly class AppController
{
    public function __construct(
        private KernelInterface $kernel,
        private Connection $connection,
        private CurrentSession $current,
        private Principals $principals,
        private SetupCodes $setupCodes,
        private RecoveryLinks $recoveryLinks,
        private Passkeys $passkeys,
        private Verifier $verifier,
        private IntentSigning $signing,
        private Flows $flows,
        private FlowContexts $contexts,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Who is signed in.
     */
    public function home(): Response
    {
        $session = $this->current->get();
        $email = null === $session ? null : $this->principals->identifiers($session->realm, $session->principalId)[IdentifierKind::Email->value] ?? null;

        return self::page('Home', null === $session ? '<p data-testid="who">Not signed in</p>' : \sprintf('<p data-testid="who">Signed in as %s</p>', htmlspecialchars((string) $email?->value)));
    }

    /**
     * What became of the last signed operation.
     */
    public function signed(Request $request): Response
    {
        $result = $request->getSession()->get(SignedOperations::RESULT);

        return self::page('Signed', \sprintf('<p data-testid="signed">%s</p>', htmlspecialchars(\is_string($result) ? $result : 'Nothing signed')));
    }

    public function reset(): Response
    {
        $this->connection->close();

        foreach (['yorvoid.sqlite', 'verifier.json', 'verifier.jsonl'] as $file) {
            @unlink(Kernel::data().'/'.$file);
        }

        $console = new Application($this->kernel);
        $console->setAutoExit(false);
        $console->run(new ArrayInput(['command' => 'yorvoid:migrate']), new NullOutput());

        return new Response(null, 204);
    }

    public function setupCode(): Response
    {
        return new Response($this->setupCodes->create(new CreateSetupCode(Realm::default(), Duration::days(1)), new Origin(OriginKind::Console))->reveal());
    }

    public function recoveryLink(Request $request): Response
    {
        $principal = $this->principals->findByIdentifier(Realm::default(), IdentifierKind::Email, $request->getPayload()->getString('email'));
        \assert(null !== $principal);

        return new Response($this->recoveryLinks->issue(new IssueRecoveryLink(Realm::default(), $principal->id), new Origin(OriginKind::Console))->secret->reveal());
    }

    /**
     * Lets the signed-in person's passkey sign operations at the verifier: it signs its own "add key"
     * intent, with the bootstrap secret the server's root would give them.
     */
    public function addKey(Request $request): Response
    {
        $session = $this->current->get();
        \assert(null !== $session);
        $passkey = $this->passkeys->of($session->realm, $session->principalId)[0];

        return $this->sign($request, $this->verifier->prepareBootstrap(Passkeys::verifierKey($passkey, 'E2E passkey')), [SignedOperations::BOOTSTRAP => $this->verifier->createBootstrapSecret()]);
    }

    public function removeSite(Request $request): Response
    {
        return $this->sign($request, $this->verifier->prepare('acme.site.remove', ['site' => 'jane']));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sign(Request $request, PreparedIntent $prepared, array $data = []): Response
    {
        $session = $this->current->get();
        \assert(null !== $session);
        $context = $this->contexts->of($request);
        $progress = $this->flows->start(PasskeyFlows::SIGN_INTENT, $session->realm, $context, IntentConfirmationStep::data($this->signing->request($session, $prepared, 'en', $context->origin)) + $data);

        return new RedirectResponse($this->urls->generate('yorvoid_flow_show', ['flow' => PasskeyFlows::SIGN_INTENT, 'id' => $progress->state->id]));
    }

    private static function page(string $title, string $body): Response
    {
        return new Response(\sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>%s</title></head><body><main><h1>%1$s</h1>%s</main></body></html>', $title, $body));
    }
}
