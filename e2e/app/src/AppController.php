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
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\IntentSigning\IntentSigning;
use YorvoID\IntentVerifier\SigningPages;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\Symfony\Flow\FlowContexts;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * The signing page's return, and endpoints only the browser tests use; Acme Team has the other pages.
 */
final readonly class AppController
{
    public function __construct(
        private KernelInterface $kernel,
        private Connection $connection,
        private CurrentSession $current,
        private Principals $principals,
        private Operations $operations,
        private Verifier $verifier,
        private IntentSigning $signing,
        private FlowContexts $contexts,
    ) {
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

        foreach (['yorvoid.sqlite', 'verifier.json', 'verifier.jsonl', 'ran.txt', 'projects.json'] as $file) {
            @unlink(Kernel::data().'/'.$file);
        }

        OffsetClock::reset();
        $console = new Application($this->kernel);
        $console->setAutoExit(false);
        $console->run(new ArrayInput(['command' => 'yorvoid:migrate']), new NullOutput());

        return new Response(null, 204);
    }

    /**
     * Moves the app's clock on, e.g. so a sign-in is older than a step-up's window.
     */
    public function clock(Request $request): Response
    {
        OffsetClock::advance($request->getPayload()->getInt('seconds'));

        return new Response(null, 204);
    }

    public function setupCode(): Response
    {
        // The test app stands in for the host's shell, from its web server: as YorvoID itself.
        return new Response($this->operations->run(new CreateSetupCode(Realm::default(), Duration::days(1)), new Operator(), new Origin(OriginKind::System, 'e2e'))->reveal());
    }

    public function recoveryLink(Request $request): Response
    {
        $principal = $this->principals->findByIdentifier(Realm::default(), IdentifierKind::Email, $request->getPayload()->getString('email'));
        \assert(null !== $principal);

        return new Response($this->operations->run(new IssueRecoveryLink(Realm::default(), $principal->id), new Operator(), new Origin(OriginKind::System, 'e2e'))->secret->reveal());
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
