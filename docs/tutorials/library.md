# Building a library on YorvoID

A library or bundle that other applications install, such as a CMS, a wiki or a shop, can use YorvoID for its people instead of keeping users of its own. This tutorial builds the YorvoID side of such a bundle, "AcmeWiki", in a Symfony application.

## One YorvoID per application

The rule that keeps this simple: **the application has one YorvoID, and configures it.** Your bundle uses that YorvoID; it never brings a second one.

- **People are the application's.** Someone who signs in to the application is signed in to your wiki, with the same session and the same passkeys. No user table of your own, no "linked account".
- **The application configures YorvoID:** its relying party, its firewall, where YorvoID's tables live (schema or prefix), its [preset](../concepts/symfony.md#presets). Your bundle doesn't set any of it.
- **Your bundle keeps its own data**, keyed by the principal's ID: a wiki profile, page ownership. Never a copy of the email or status: read those from YorvoID, so they can't drift apart.

## Depend on the bundle

```json
{
    "require": {
        "yorvoid/symfony": "^0.1"
    }
}
```

Check that the application enabled YorvoID, and say how to fix it when it didn't, rather than fail later with a missing service:

```php
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class AcmeWikiBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('yorvoid')) {
            throw new \LogicException('AcmeWiki signs people in with YorvoID: enable YorvoID\Symfony\YorvoIDBundle, and configure it (https://github.com/yorvoid/yorvoid/blob/main/docs/tutorials/symfony-quickstart.md).');
        }
    }
}
```

## Who is signed in

YorvoID's services are ready to inject. `CurrentSession` gives the request's YorvoID session, or `null`:

```php
use Symfony\Component\HttpFoundation\Response;
use YorvoID\Symfony\Security\CurrentSession;

final readonly class EditPageController
{
    public function __construct(private CurrentSession $current, private WikiPages $pages) {}

    public function __invoke(string $slug): Response
    {
        $session = $this->current->get() ?? throw new AccessDeniedHttpException();
        $page = $this->pages->edit($slug, author: $session->principalId);

        // …
    }
}
```

The application's firewall, voters and `#[IsGranted]` work too: its user is identified by the principal's ID (`getUserIdentifier()`), whatever user class the application chose.

## Keep your data next to YorvoID's

Hear when people arrive, and give them what your bundle keeps. YorvoID's events go through Symfony's dispatcher; "-ed" events wait for the commit, so you never act on a change that was rolled back:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use YorvoID\Core\Principal\Event\PrincipalCreated;

#[AsEventListener]
final readonly class CreateWikiProfile
{
    public function __construct(private WikiProfiles $profiles) {}

    public function __invoke(PrincipalCreated $event): void
    {
        $this->profiles->create($event->principal->realm, $event->principal->id);
    }
}
```

"-ing" events, such as `PrincipalStatusChanging`, run inside YorvoID's transaction: a listener that throws stops the change ([operations and events](../concepts/operations-and-events.md)).

## Ask for a recent proof before sensitive operations

Deleting a wiki, or handing it to someone else, deserves more than a session started this morning. `StepUp` checks the session's proof, and says what's missing:

```php
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;

try {
    $this->stepUp->require($session, new AuthenticationRequirement(Duration::minutes(5), multiFactor: true, operation: 'acme_wiki.wiki.delete'));
} catch (StepUpRequired) {
    // YorvoID's step-up page asks for the passkey, for this operation only.
    return new RedirectResponse($this->urls->generate('yorvoid_flow_start', ['flow' => 'yorvoid.step_up', 'operation' => 'acme_wiki.wiki.delete', 'multi_factor' => '1']));
}
```

- **Name operations after your bundle** (`acme_wiki.…`): a proof for one operation doesn't count for another, so yours never mix with the application's.
- When the step-up completes, the person goes where the application's `flows.completed_paths` sends `yorvoid.step_up`; document that your bundle needs it to come back to your page.

## Add a step to a flow

Ask for your wiki's terms when someone signs in for the first time, or anything else your bundle needs from people. A `FlowCustomizer` gets every flow, and changes the ones it's for:

```php
use YorvoID\Flows\Flow;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Symfony\Flow\FlowCustomizer;

final readonly class WikiTermsCustomizer implements FlowCustomizer
{
    public function __construct(private AcceptWikiTermsStep $terms) {}

    public function customize(Flow $flow): Flow
    {
        return PasskeyFlows::SIGN_IN === $flow->name ? $flow->withStepAfter('passkey', $this->terms) : $flow;
    }
}
```

The bundle autoconfigures every `FlowCustomizer`. Give your step a name of its own (`acme_wiki_terms`), and a template, `templates/bundles/YorvoIDBundle/flow/acme_wiki_terms.html.twig`; steps are explained in [flows](../concepts/flows.md#writing-a-step).

## Test it

In your bundle's own test application, configure YorvoID as an application would, and use the [test helpers](../concepts/testing.md):

```php
$scenario = Scenario::of(static::getContainer());
$ana = $scenario->principal();
BrowserSignIn::as($client, $ana);
$client->request('GET', '/wiki/home/edit');
```

## Checklist

- [ ] No YorvoID configuration in your bundle's recipe or `prependExtension()`: the application's.
- [ ] No user table: principals, keyed by ID.
- [ ] Your operations and steps named after your bundle.
- [ ] Your docs say: install and configure YorvoID first ([quickstart](symfony-quickstart.md)), and set `completed_paths` for your step-ups.
