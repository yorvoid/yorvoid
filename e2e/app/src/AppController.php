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
use YorvoID\IntentSigning\IntentSigning;
use YorvoID\IntentVerifier\SigningPages;
use YorvoID\IntentVerifier\Verifier;
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
        private Verifier $verifier,
        private IntentSigning $signing,
        private FlowContexts $contexts,
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
     * Where the signing page sends the browser back: what the operation did, as the console would learn it
     * from the verifier, not from the query.
     */
    public function signed(Request $request): Response
    {
        $ran = is_file(Signing::ran()) ? (string) file_get_contents(Signing::ran()) : 'Nothing ran';

        return self::page('Signed', \sprintf('<p data-testid="outcome">%s</p><p data-testid="signed">%s</p>', htmlspecialchars($request->query->getString('outcome')), htmlspecialchars($ran)));
    }

    public function reset(): Response
    {
        $this->connection->close();

        foreach (['yorvoid.sqlite', 'verifier.json', 'verifier.jsonl', 'ran.txt'] as $file) {
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
     * A bootstrap secret, as the host's root would create one with a console command.
     */
    public function bootstrapSecret(): Response
    {
        return new Response($this->verifier->createBootstrapSecret());
    }

    /**
     * Asks for an operation, as a console would: the verifier prepares it, and the person is sent to the
     * signing page to sign it.
     */
    public function removeSite(Request $request): Response
    {
        $session = $this->current->get();
        \assert(null !== $session);
        $prepared = $this->verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->signing->request($session, $prepared->intent, $this->contexts->of($request)->origin);

        return new RedirectResponse(SigningPages::url(Signing::config(), $prepared));
    }

    private static function page(string $title, string $body): Response
    {
        return new Response(\sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>%s</title></head><body><main><h1>%1$s</h1>%s</main></body></html>', $title, $body));
    }
}
