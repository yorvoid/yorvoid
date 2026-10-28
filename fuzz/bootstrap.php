<?php

declare(strict_types=1);

/*
 * Shared by the fuzz targets. php-fuzzer reports only Errors as bugs; YorvoID is stricter: the only
 * acceptable way for its code to refuse input is an IntegratorError (a clear "this input is invalid").
 * Any other exception, and any PHP warning or notice, is reported as a bug.
 */

use YorvoID\Core\Exception\IntegratorError;

require dirname(__DIR__).'/vendor/autoload.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new Error(sprintf('PHP warning or notice: %s in %s:%d', $message, $file, $line));
});

/**
 * @param Closure(string): void $target
 *
 * @return Closure(string): void
 */
function onlyRefusals(Closure $target): Closure
{
    return static function (string $input) use ($target): void {
        try {
            $target($input);
        } catch (IntegratorError) {
            // A clear refusal of invalid input: fine.
        } catch (Throwable $unexpected) {
            throw new Error(sprintf('Unexpected %s: %s', $unexpected::class, $unexpected->getMessage()), 0, $unexpected);
        }
    };
}
