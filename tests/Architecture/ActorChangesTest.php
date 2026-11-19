<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Evaluator;

/**
 * The evaluator keeps why an actor isn't proven beside the request (D-45). A request made for another
 * actor, by by() or withActor(), is a new key, without it, unless the method that makes it is provenAs(),
 * which proves the actor, or copies the reason. A view-as lost it once: its sides were judged as if the
 * viewer were proven. Until the reason is a field of the Request, which by() copies (before M3-8), this
 * reads every method of the evaluator for it.
 */
final class ActorChangesTest extends TestCase
{
    public function testEveryActorChangeInTheEvaluatorKeepsWhyItIsntProven(): void
    {
        $class = new \ReflectionClass(Evaluator::class);
        $lines = file((string) $class->getFileName());
        self::assertIsArray($lines);
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[$method->getName()] = implode('', \array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
        }

        self::assertContains('provenAs', array_keys(self::changingActors($methods)), 'provenAs() no longer changes the actor: update this test.');
        self::assertContains('sides', array_keys(self::changingActors($methods)), 'sides() no longer changes the actor: update this test.');
        self::assertSame([], self::losingTheReason($methods), 'These make a request for another actor without why the actor isn\'t proven: copy $this->unproven to it, as sides() does.');
    }

    public function testAnActorChangeThatDoesntCopyTheReasonIsFound(): void
    {
        $methods = [
            'provenAs' => 'return $request->withActor($proven);',
            'copies' => '$side = $request->by($viewer); $this->unproven[$side] = $unproven;',
            'loses' => 'return [$request->by($actor->viewer), $request->by($actor->target)];',
            'unrelated' => 'return $request;',
        ];

        self::assertSame(['loses'], self::losingTheReason($methods));
    }

    /**
     * @param array<string, string> $methods Each method's source, by name
     *
     * @return array<string, string>
     */
    private static function changingActors(array $methods): array
    {
        return array_filter($methods, static fn (string $source): bool => 1 === preg_match('/->(?:by|withActor)\(/', $source));
    }

    /**
     * @param array<string, string> $methods Each method's source, by name
     *
     * @return list<string>
     */
    private static function losingTheReason(array $methods): array
    {
        $losing = array_filter(self::changingActors($methods), static fn (string $source, string $name): bool => 'provenAs' !== $name && 1 !== preg_match('/\$this->unproven\[[^\]]+\]\s*=(?!=)/', $source), \ARRAY_FILTER_USE_BOTH);

        return array_keys($losing);
    }
}
