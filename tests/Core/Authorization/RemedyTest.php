<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;

/**
 * Remedies say what they ask for in AuthZEN ARAP's terms: a name and its parameters.
 */
final class RemedyTest extends TestCase
{
    public function testAStepUpSaysHowRecentAndWhatKindOfProof(): void
    {
        $plain = new StepUpRemedy(Duration::minutes(5));
        $strict = new StepUpRemedy(Duration::minutes(15), multiFactor: true, phishingResistant: true, operation: 'acme.badge.issue');

        self::assertSame('step_up', $plain->name());
        self::assertSame(['max_age' => 300, 'multi_factor' => false, 'phishing_resistant' => false, 'operation' => null], $plain->parameters());
        self::assertSame(['max_age' => 900, 'multi_factor' => true, 'phishing_resistant' => true, 'operation' => 'acme.badge.issue'], $strict->parameters());
    }

    public function testAWaitSaysUntilWhen(): void
    {
        $wait = new WaitRemedy(new \DateTimeImmutable('2026-11-08 12:00:00 UTC'));

        self::assertSame('wait', $wait->name());
        self::assertSame(['until' => '2026-11-08T12:00:00+00:00'], $wait->parameters());
    }

    public function testAFailingConditionListsItsRemedies(): void
    {
        $remedy = new StepUpRemedy(Duration::minutes(5));

        self::assertSame([$remedy], Truth::fails('Prove it again.', ...['step-up' => $remedy])->remedies);
        self::assertSame([], Truth::fails('No.')->remedies);
    }
}
