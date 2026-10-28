<?php

declare(strict_types=1);

namespace YorvoID\Tests\Flows;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;
use YorvoID\Flows\FlowState;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Storage\InMemoryFlowStore;

final class FlowStateTest extends TestCase
{
    public function testKeepsPlainData(): void
    {
        $state = new FlowState('id', 'acme.one', new Realm('acme'), new \DateTimeImmutable('2026-10-28 18:30'));

        $kept = $state->with(['name' => 'Ana', 'age' => 3, 'codes' => ['A', 'B'], 'nested' => ['ok' => [true, null, 1.5]]])->with(['age' => 4]);

        self::assertSame(['name' => 'Ana', 'age' => 4, 'codes' => ['A', 'B'], 'nested' => ['ok' => [true, null, 1.5]]], $kept->data);
        self::assertSame([[], null], [$state->data, $state->get('name')], 'States don\'t change.');
        self::assertSame(['Ana', null, 4, null], [$kept->string('name'), $kept->string('age'), $kept->get('age'), $kept->get('missing')]);
        self::assertTrue($kept->with(['empty' => null])->has('empty'));
        self::assertFalse($kept->has('empty'));
        self::assertSame(['age' => 4, 'nested' => ['ok' => [true, null, 1.5]]], $kept->without(['name', 'codes', 'missing'])->data);
        self::assertSame(['id', 'acme.one', '2026-10-28 18:30'], [$kept->without([])->id, $kept->without([])->flow, $kept->without([])->expiresAt->format('Y-m-d H:i')]);
        self::assertTrue($kept->without([])->realm->equals(new Realm('acme')));

        $moved = $kept->at('first')->passing('first')->at('second');
        self::assertSame([['first'], 'second', $kept->data], [$moved->passed, $moved->current, $moved->with([])->data]);
        self::assertSame(['first'], $moved->without([])->passed);
        self::assertSame('second', $moved->without(['x'])->current);
        self::assertSame('second', $moved->with(['x' => 1])->current);
        self::assertSame(['first'], $moved->with(['x' => 1])->passed);
    }

    public function testIsKeptAsPlainData(): void
    {
        $state = new FlowState('id', 'acme.one', new Realm('acme'), new \DateTimeImmutable('@1793210000'), ['first'], 'second', ['name' => 'Ana', 'codes' => ['A']]);

        $stored = $state->toArray();

        self::assertSame(['id' => 'id', 'flow' => 'acme.one', 'realm' => 'acme', 'expires_at' => 1793210000, 'passed' => ['first'], 'current' => 'second', 'data' => ['name' => 'Ana', 'codes' => ['A']]], $stored);
        self::assertEquals($state, FlowState::fromArray($stored));
        self::assertEquals($state->at(null), FlowState::fromArray(['current' => null] + $stored));

        foreach ([null, 'x', [], ['id' => 5] + $stored, ['flow' => null] + $stored, ['realm' => 1] + $stored, ['expires_at' => '1'] + $stored, ['passed' => 'first'] + $stored, ['passed' => [1 => 'a']] + $stored, ['passed' => [1]] + $stored, ['current' => 3] + $stored, ['data' => 'x'] + $stored, ['data' => ['x', 'y']] + $stored] as $broken) {
            self::assertNull(FlowState::fromArray($broken), var_export($broken, true));
        }
    }

    public function testRefusesObjects(): void
    {
        $state = new FlowState('id', 'acme.one', new Realm('acme'), new \DateTimeImmutable());

        foreach (['session' => new \stdClass(), 'deep' => ['list' => [new \DateTimeImmutable()]]] as $key => $value) {
            try {
                $state->with([$key => $value]);
                self::fail('An object was kept.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('A flow step kept "', $error->problem);
                self::assertSame('concepts/flows.md#writing-a-step', $error->docs);
            }
        }

        $this->expectExceptionObject(new IntegratorError('A flow step kept "session", a stdClass.', 'Keep plain data in the flow\'s state: strings, numbers, booleans, null, and lists and maps of them. Keep an object\'s ID, and load it again.', 'concepts/flows.md#writing-a-step'));

        $state->with(['session' => new \stdClass()]);
    }

    public function testReadsWhatThePersonSent(): void
    {
        $object = new \stdClass();
        $object->id = 'AAE';
        $input = new Input(['name' => 'Ana', 'count' => 3, 'ticked' => 'on', 'one' => '1', 'yes' => true, 'no' => '0', 'off' => false, 'response' => ['id' => 'AAE'], 'raw' => '{"id":"AAE"}', 'object' => $object, 'action' => 'skip']);

        self::assertSame(['Ana', null, null], [$input->string('name'), $input->string('count'), $input->string('missing')]);
        self::assertSame([true, true, true, false, false, false, false], [$input->isChecked('ticked'), $input->isChecked('one'), $input->isChecked('yes'), $input->isChecked('no'), $input->isChecked('off'), $input->isChecked('missing'), $input->isChecked('count')]);
        self::assertSame(['{"id":"AAE"}', '{"id":"AAE"}', '{"id":"AAE"}', null, null], [$input->json('response'), $input->json('raw'), $input->json('object'), $input->json('count'), $input->json('missing')]);
        self::assertSame([Input::SKIP, null], [$input->action(), new Input()->action()]);
    }

    public function testProblemsAndTheMemoryStore(): void
    {
        self::assertSame(['message' => 'a.b', 'parameters' => ['n' => 1], 'field' => 'code', 'reason' => 'r'], new Problem('a.b', ['n' => 1], 'code', 'r')->jsonSerialize());
        self::assertSame(['message' => 'a.b', 'parameters' => [], 'field' => null, 'reason' => null], new Problem('a.b')->jsonSerialize());

        $store = new InMemoryFlowStore();
        $state = new FlowState('id', 'acme.one', new Realm('acme'), new \DateTimeImmutable());
        $store->save($state);
        self::assertSame($state, $store->get('id'));
        $store->delete('id');
        self::assertNull($store->get('id'));
    }
}
