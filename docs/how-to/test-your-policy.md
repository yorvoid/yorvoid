# Test your policy

State the decisions your policy must give, and check them on every change, in CI too (AUZ-043). The tests run on your policy as production builds it, with only YorvoID's stores in memory: your declarations, roles, rules, relations, attributes and your own permits and forbids, decided by YorvoID's evaluator. The package is for development only, and experimental in 0.x:

```bash
composer require --dev yorvoid/policy-testing
```

## Cases

A case is one decision: who the actor is, what it asks, and what the policy answers.

```php
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyCases;

final readonly class AcmeCases implements PolicyCases
{
    public function cases(): iterable
    {
        $page = new ResourceRef('page', '1');

        yield PolicyCase::that('Anyone views pages')->anonymous()->asks('acme.pages.view', $page)->permits();
        yield PolicyCase::that('A member doesn\'t publish')->given(roles: ['member'])->asks('acme.pages.publish', $page)->denies(remedies: []);
        yield PolicyCase::that('An editor of a page publishes it')->given(on: [['editor', $page]])->asks('acme.pages.publish', $page)->permits();
        yield PolicyCase::that('A group\'s editors publish')->given(groups: ['writers' => ['editor']])->asks('acme.pages.publish', $page)->permits();
        yield PolicyCase::that('Nobody publishes a locked page')->given(roles: ['admin'])->asks('acme.pages.publish', new ResourceRef('page', 'locked'))->denies(precedence: Precedence::ForbidHolds);
        yield PolicyCase::that('A suspended editor publishes nothing')->given(roles: ['editor'], status: PrincipalStatus::Suspended)->asks('acme.pages.publish', $page)->denies();
        yield PolicyCase::that('A super role purges once it\'s granted to it')
            ->before(static fn (Realm $realm): Command => new GrantAbilities($realm, 'admin', ['acme.pages.purge']))
            ->given(roles: ['admin'])
            ->asks('acme.pages.purge', $page)
            ->permits();
    }
}
```

- **Given:**
  - `given()` sets up the actor, a principal created for the case, with:
    - roles in the whole realm (`roles`) and on a resource (`on`);
    - groups it's a member of, with their roles (`groups`);
    - its relations to resources (`relations`);
    - its security attributes (`attributes`);
    - its status (`status`).
  - `anonymous()` makes the actor someone not signed in.
  - `before()` runs operations first as the host's operator, e.g. creating a role in the realm or granting it an ability. Give each a command, or a function that makes one for the policy's realm.
- **Asks:** `asks()` takes the ability, the resource and the subject (your own object, for rules).
- **Then:**
  - `permits()`;
  - `denies()`, optionally with the remedies' names (`[]` for none) and the precedence row;
  - `indeterminate()`, optionally with why it can't decide.
- **What passes:** a case passes on its verdict. The remedies, the row and the reasons count only when you state them, so rewording a reason doesn't break your tests.
- **Fresh stores:** each case starts with nothing stored, and is set up through YorvoID's operations as production's would be.
- **Descriptions:** each case's description names it in the report, so no two can be the same.

## In Symfony

The policy under test is your application's own container, in an environment of its own, `yorvoid_policy`, where the bundle puts YorvoID's stores in memory and changes nothing else. Enable it there only:

```php
// config/bundles.php
return [
    // ...
    YorvoID\PolicyTesting\Symfony\YorvoIDPolicyTestingBundle::class => ['yorvoid_policy' => true],
];
```

A service implementing `PolicyCases` is collected by itself. Run them:

```bash
bin/console yorvoid:policy:test --env=yorvoid_policy
bin/console yorvoid:policy:test --env=yorvoid_policy --format=json
```

It fails when a case does. To run them with your tests instead, one PHPUnit test per case:

```php
use App\Kernel;
use PHPUnit\Framework\TestCase;
use YorvoID\PolicyTesting\PolicyCasesTest;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\Symfony\PolicyKernel;

final class PolicyTest extends TestCase
{
    use PolicyCasesTest;

    protected static function policySuite(): PolicySuite
    {
        return PolicyKernel::suite(new Kernel('yorvoid_policy', false));
    }
}
```

What the tests check, and what they don't:

- **Your configuration:** the `yorvoid_policy` environment loads only your shared configuration, not what's under `when@prod`, `when@test` or `config/packages/prod/`. Keep what your policy depends on (declarations, rules, role sources, attribute resolvers) out of the production-only configuration, or the tests check another policy than the one that runs.
- **The stores:** YorvoID's stores are in memory, without the cache production reads them through. The cache has tests of its own.
- **No database:** YorvoID's database connection isn't there at all. Something that asks for it fails the tests rather than reaching a database.
- **Your own services:** a rule or attribute resolver of yours that reads your own database still reads it, since YorvoID can't put that in memory. Give the case what it would read with `given(attributes: ...)`, or replace that service in the `yorvoid_policy` environment.

## Without Symfony

Build the policy under test with the function production builds it with, given in-memory stores, so the tests check the policy that runs:

```php
use Acme\Security;
use Acme\Tests\AcmeCases;
use YorvoID\PolicyTesting\PolicyServices;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\PolicyUnderTest;
use YorvoID\Testing\InMemoryStorage;

// tests/policy.php
$policy = PolicyUnderTest::of(static function (InMemoryStorage $stores): PolicyServices {
    [$operations, $policy] = Security::build($stores->policy, $stores->relations, $stores->attributes, $stores->principals, $stores->log, $stores->transactions);

    return new PolicyServices($operations, $policy, Security::realm());
});

return new PolicySuite($policy, new AcmeCases());
```

```bash
vendor/bin/yorvoid-policy test --bootstrap=tests/policy.php
```

`PolicyCasesTest` works the same: return that suite from `policySuite()`.
