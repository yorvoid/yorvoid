<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\ConcernsNoResource;
use YorvoID\Core\Operation\ConcernsResource;

/**
 * What decideMany() and Symfony's isGranted() both refuse to take as a resource with its subject: one
 * list for both, so that they agree (fix 6, C3).
 */
final class SubjectShapes
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        $page = new ResourceRef('page', '1');
        $subject = new \stdClass();

        yield 'a string' => ['acme'];
        yield 'an integer' => [1];
        yield 'an object that says nothing' => [$subject];
        yield 'a resource alone in a list' => [[$page]];
        yield 'a resource and a string' => [[$page, 'acme']];
        yield 'a resource and nothing' => [[$page, null]];
        yield 'a resource\'s name and a subject' => [['page:1', $subject]];
        yield 'one too many' => [[$page, $subject, $subject]];
        yield 'out of order' => [[1 => $subject, 0 => $page]];
        yield 'keyed' => [['resource' => $page, 'subject' => $subject]];
    }

    /**
     * An object that says it concerns a resource, and that it concerns none.
     */
    public static function both(): object
    {
        return new class implements ConcernsResource, ConcernsNoResource {
            public function resource(): ResourceRef
            {
                return new ResourceRef('page', '1');
            }
        };
    }

    /**
     * An object about no resource.
     */
    public static function none(): ConcernsNoResource
    {
        return new class implements ConcernsNoResource {
        };
    }
}
