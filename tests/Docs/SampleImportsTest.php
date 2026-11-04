<?php

declare(strict_types=1);

namespace YorvoID\Tests\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every PHP block in the docs names the classes it uses: imported, written in full, declared in the
 * block, or PHP's own. A block copied on its own then fails only for what it says it leaves out (the
 * services it's given), never for a missing "use" line.
 */
final class SampleImportsTest extends TestCase
{
    private const string DOCS = __DIR__.'/../../docs';

    /** Names that aren't classes where a class could stand. */
    private const array NOT_CLASSES = ['self', 'static', 'parent', 'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'string', 'true', 'void', 'list', 'class'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blocks(): iterable
    {
        $finder = new Finder()->files()->in(self::DOCS)->name('*.md')->notPath('node_modules')->sortByName();

        foreach ($finder as $file) {
            preg_match_all('/^```php\n(.*?)^```/ms', $file->getContents(), $blocks, \PREG_OFFSET_CAPTURE);

            // A tutorial's blocks are one script: what an earlier block imported, a later one may use.
            $earlier = '';

            foreach ($blocks[1] as [$code, $offset]) {
                $line = substr_count(substr($file->getContents(), 0, $offset), "\n") + 1;
                $tutorial = str_starts_with($file->getRelativePathname(), 'tutorials/');
                preg_match_all('/^use [^;]+;$/m', $code, $uses);

                yield \sprintf('%s:%d', $file->getRelativePathname(), $line) => [$file->getRelativePathname(), $tutorial ? $earlier.$code : $code];

                $earlier .= $tutorial ? implode("\n", $uses[0])."\n" : '';
            }
        }
    }

    #[DataProvider('blocks')]
    public function testTheBlockImportsWhatItUses(string $page, string $code): void
    {
        self::assertSame([], self::unimported($code), 'Import these, or write them in full.');
    }

    public function testFindsWhatABlockLeavesOut(): void
    {
        self::assertSame(['Duration', 'Origin', 'OriginKind', 'Secret', 'StepUp', 'UserInterface'], self::unimported(<<<'PHP'
            use YorvoID\Core\Realm;
            use YorvoID\Authn\Setup\CreateSetupCode as Create;

            final class Reader implements \Countable
            {
                public function __construct(private StepUp $stepUp) {}
                public function count(): int { return 0; }
                public function user(): ?UserInterface { return null; }
            }

            $code = $setupCodes->create(new Create(Realm::default(), Duration::days(1)), new Origin(OriginKind::Console));
            try { Secret::fromString('x'); } catch (\LogicException|Reader $e) {}
            $x instanceof Reader;
            new \DateTimeImmutable();
            new DateTimeImmutable();
            PHP));
    }

    /**
     * @return list<string> The classes the code uses without importing or declaring them, sorted
     */
    private static function unimported(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize(str_starts_with($code, '<?php') ? $code : "<?php\n".$code),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $known = [];
        $used = [];
        $namespaced = false;

        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            if ($token->is(\T_NAMESPACE)) {
                $namespaced = true;
            }

            // use A\B; use A\B as C; use A\{B, C}; at the top level only (not closures' use, nor traits').
            if ($token->is(\T_USE) && (null === $previous || $previous->is([';', '{', '}', \T_OPEN_TAG]))) {
                $statement = '';

                for ($j = $i + 1; isset($tokens[$j]) && !$tokens[$j]->is(';'); ++$j) {
                    $statement .= $tokens[$j]->text.' ';
                }

                if (1 !== preg_match('/^(function|const) /', $statement)) {
                    foreach ((array) preg_split('/[{},]/', $statement) as $part) {
                        // The last word: the alias after "as", or the name's last segment.
                        if (1 === preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*$/', (string) $part, $last)) {
                            $known[$last[1]] = true;
                        }
                    }
                }

                continue;
            }

            if ($token->is([\T_CLASS, \T_INTERFACE, \T_ENUM, \T_TRAIT]) && null !== $next && $next->is(\T_STRING) && !(null !== $previous && $previous->is(\T_DOUBLE_COLON))) {
                $known[$next->text] = true;

                continue;
            }

            if (!$token->is(\T_STRING) || \in_array(strtolower($token->text), self::NOT_CLASSES, true)) {
                continue;
            }

            $asClass = (null !== $next && $next->is([\T_DOUBLE_COLON, \T_VARIABLE, \T_ELLIPSIS]))
                || (null !== $previous && $previous->is([\T_NEW, \T_INSTANCEOF, \T_EXTENDS, \T_IMPLEMENTS, \T_ATTRIBUTE]))
                || (null !== $previous && $previous->is(',') && self::inList($tokens, $i))
                || self::isReturnType($tokens, $i)
                || (null !== $previous && $previous->is('(') && isset($tokens[$i - 2]) && $tokens[$i - 2]->is(\T_CATCH));

            if ($asClass && !isset($known[$token->text]) && ($namespaced || !self::isPhps($token->text))) {
                $used[$token->text] = true;
            }
        }

        $names = array_keys($used);
        sort($names);

        return $names;
    }

    /**
     * Whether a name after a comma is in "implements A, B" or "catch (A | B" rather than arguments.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function inList(array $tokens, int $at): bool
    {
        for ($j = $at - 1; $j >= 0; --$j) {
            if ($tokens[$j]->is(\T_IMPLEMENTS)) {
                return true;
            }

            if (!$tokens[$j]->is([',', \T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the name is a return type: "): Name" or "): ?Name".
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isReturnType(array $tokens, int $at): bool
    {
        $j = $at - 1;

        if (isset($tokens[$j]) && $tokens[$j]->is('?')) {
            --$j;
        }

        return isset($tokens[$j], $tokens[$j - 1]) && $tokens[$j]->is(':') && $tokens[$j - 1]->is(')');
    }

    private static function isPhps(string $name): bool
    {
        foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $declared) {
            if ($declared === $name) {
                return new \ReflectionClass($declared)->isInternal();
            }
        }

        return false;
    }
}
