<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use YorvoID\Core\Realm;
use YorvoID\Flows\FlowState;
use YorvoID\Symfony\Flow\SessionFlowStore;

final class SessionFlowStoreTest extends TestCase
{
    public function testFlowsNobodyGotAnywhereInGoFirstNewestFirst(): void
    {
        $store = self::store();
        $store->save(self::state('in-progress', passed: ['passkey']));
        $store->save(self::state('opened-first'));

        for ($i = 1; $i <= 10; ++$i) {
            $store->save(self::state('linked-'.$i));
        }

        self::assertSame(['in-progress', 'opened-first', 'linked-1', 'linked-2', 'linked-10'], self::ids($store));
    }

    public function testWhenEveryFlowGotSomewhereTheLeastRecentlyUsedGoes(): void
    {
        $store = self::store();

        for ($i = 1; $i <= SessionFlowStore::LIMIT + 1; ++$i) {
            $store->save(self::state('flow-'.$i, passed: ['first']));
        }

        self::assertSame(['flow-2', 'flow-3', 'flow-4', 'flow-5', 'flow-6'], self::ids($store));

        // The one in use is the latest used.
        $store->save(self::state('flow-2', passed: ['first', 'second']));
        $store->save(self::state('flow-7', passed: ['first']));
        self::assertSame(['flow-2', 'flow-4', 'flow-5', 'flow-6', 'flow-7'], self::ids($store));
        self::assertSame(['first', 'second'], $store->get('flow-2')?->passed);
    }

    public function testWhatIsntAFlowGoesFirst(): void
    {
        $requests = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests->push($request);
        $store = new SessionFlowStore($requests);

        for ($i = 1; $i < SessionFlowStore::LIMIT; ++$i) {
            $store->save(self::state('flow-'.$i, passed: ['first']));
        }

        $flows = $request->getSession()->get(SessionFlowStore::KEY);
        self::assertIsArray($flows);
        $request->getSession()->set(SessionFlowStore::KEY, ['broken' => 'not a flow'] + $flows);
        $store->save(self::state('flow-5', passed: ['first']));

        self::assertSame(['flow-1', 'flow-2', 'flow-3', 'flow-4', 'flow-5'], self::ids($store));
    }

    private static function store(): SessionFlowStore
    {
        $requests = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests->push($request);

        return new SessionFlowStore($requests);
    }

    /**
     * @param list<string> $passed
     */
    private static function state(string $id, array $passed = []): FlowState
    {
        return new FlowState($id, 'yorvoid.sign_in', Realm::default(), new \DateTimeImmutable('+1 hour'), $passed);
    }

    /**
     * @return list<string>
     */
    private static function ids(SessionFlowStore $store): array
    {
        $ids = [];

        foreach (['in-progress', 'opened-first', 'broken', ...array_map(static fn (int $i): string => 'linked-'.$i, range(1, 10)), ...array_map(static fn (int $i): string => 'flow-'.$i, range(1, 7))] as $id) {
            if (null !== $store->get($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
