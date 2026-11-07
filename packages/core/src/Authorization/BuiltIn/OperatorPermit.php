<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operator;

/**
 * The host's operator, from the host: a console command or a job, which run from the command line, or
 * YorvoID itself (OriginKind::System), e.g. a notification's token after a request's operation. A web
 * request saying it's the console isn't.
 *
 * @internal
 */
final readonly class OperatorPermit implements PermitElement
{
    public function __construct(
        private bool $commandLine,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.operator';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Operator;
    }

    public function matches(Request $request): bool
    {
        return $request->actor instanceof Operator;
    }

    public function evaluate(Request $request): Truth
    {
        $fromTheHost = match ($request->origin->kind) {
            OriginKind::Console, OriginKind::Job => $this->commandLine,
            OriginKind::System => true,
            OriginKind::Web, OriginKind::Api => false,
        };

        return $fromTheHost
            ? Truth::holds('The host\'s operator.')
            : Truth::fails(\sprintf('The host\'s operator acts from the command line or as the system, not from "%s".', $request->origin->kind->value));
    }
}
