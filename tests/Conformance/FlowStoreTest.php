<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use YorvoID\Flows\FlowStore;
use YorvoID\Flows\Storage\InMemoryFlowStore;
use YorvoID\Symfony\Flow\SessionFlowStore;
use YorvoID\Testing\Contract\FlowStoreContract;

/**
 * Every FlowStore keeps the same promises: in memory, and in the Symfony bridge's session.
 */
final class FlowStoreTest extends TestCase
{
    use FlowStoreContract;

    private ?FlowStore $store = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function implementations(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'session' => ['session'];
    }

    protected function flowStore(string $implementation): FlowStore
    {
        return $this->store ??= match ($implementation) {
            'memory' => new InMemoryFlowStore(),
            'session' => self::sessionStore(),
            default => throw new \LogicException('Unknown store '.$implementation),
        };
    }

    private static function sessionStore(): SessionFlowStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests = new RequestStack();
        $requests->push($request);

        return new SessionFlowStore($requests);
    }
}
