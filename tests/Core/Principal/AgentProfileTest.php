<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\AgentProfile;

/**
 * An agent's profile: each field text of 1 to 200 characters, the optional ones left out or given.
 */
final class AgentProfileTest extends TestCase
{
    private const array FIELDS = ['name', 'software', 'version', 'operator'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        foreach (self::FIELDS as $field) {
            yield $field.', empty' => [$field, ''];
            yield $field.', blank' => [$field, " \t "];
            yield $field.', 201 characters' => [$field, str_repeat('é', 201)];
            yield $field.', not UTF-8' => [$field, "\xC3\x28"];
        }
    }

    #[DataProvider('refused')]
    public function testRefusesAFieldThatIsntShortText(string $field, string $value): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage(\sprintf('An agent\'s %s must be text of 1 to 200 characters.', $field));

        new AgentProfile(...['name' => 'Assistant', $field => $value]);
    }

    public function testTakes200CharactersInEachFieldCountedAsCharacters(): void
    {
        $long = str_repeat('é', 200);
        $profile = new AgentProfile($long, $long, $long, $long);

        self::assertSame(['name' => $long, 'software' => $long, 'version' => $long, 'operator' => $long], $profile->toArray());
        self::assertEquals($profile, AgentProfile::fromArray($profile->toArray()));
        self::assertEquals(new AgentProfile('Assistant'), AgentProfile::fromArray(['name' => 'Assistant']), 'The optional fields left out.');
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function notStored(): iterable
    {
        yield 'no name' => [['software' => 'acme-agent'], 'A stored agent profile has no name.'];
        yield 'a name that isn\'t text' => [['name' => 5], 'A stored agent profile\'s "name" isn\'t text.'];
        yield 'a field that isn\'t text' => [['name' => 'Assistant', 'version' => ['2']], 'A stored agent profile\'s "version" isn\'t text.'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('notStored')]
    public function testRefusesWhatIsntAStoredProfile(array $data, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        AgentProfile::fromArray($data);
    }
}
