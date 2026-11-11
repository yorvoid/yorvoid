<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\HttpFoundation\Response;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Realm;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Subject;

/**
 * An application's page that relates what the request names, as the operator would: a reserved type, or an
 * undeclared relation, must be a 400, never a 500 (M2-13).
 */
final readonly class RelateController
{
    public function __construct(
        private Operations $operations,
    ) {
    }

    public function __invoke(string $type, string $relation): Response
    {
        $this->operations->run(new AddRelation(new Realm('default'), new ResourceRef($type, 'x'), $relation, new Subject(new ResourceRef('page', 'y'))), new Operator(), new Origin(OriginKind::Console));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
