<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;

final class DecisionTest extends TestCase
{
    public function testOnlyAPermitPermits(): void
    {
        self::assertTrue(Decision::permit(AuthorizedBy::Self, 'yes')->permits());
        self::assertFalse(new Decision(Verdict::PermitOffline, AuthorizedBy::Self, 'offline')->permits(), 'Permit-offline isn\'t a Permit (P1′).');
        self::assertFalse(Decision::deny('no')->permits());
        self::assertFalse(Decision::indeterminate([IndeterminateReason::MissingData], 'can\'t tell')->permits());
    }

    public function testEachVerdictHasItsOwnParts(): void
    {
        $remedy = new WaitRemedy(new \DateTimeImmutable('2026-11-08 12:00:00 UTC'));

        foreach ([
            'a permit without a basis' => [Verdict::Permit, null, [], []],
            'a permit with remedies' => [Verdict::Permit, AuthorizedBy::Self, [$remedy], []],
            'a permit that couldn\'t decide' => [Verdict::Permit, AuthorizedBy::Self, [], [IndeterminateReason::MissingData]],
            'permit-offline without a basis' => [Verdict::PermitOffline, null, [], []],
            'a deny with a basis' => [Verdict::Deny, AuthorizedBy::Self, [], []],
            'a deny that couldn\'t decide' => [Verdict::Deny, null, [], [IndeterminateReason::MissingData]],
            'Indeterminate without a reason' => [Verdict::Indeterminate, null, [], []],
            'Indeterminate with a basis' => [Verdict::Indeterminate, AuthorizedBy::Self, [], [IndeterminateReason::MissingData]],
            'Indeterminate with remedies' => [Verdict::Indeterminate, null, [$remedy], [IndeterminateReason::MissingData]],
        ] as $what => [$verdict, $basis, $remedies, $indeterminate]) {
            try {
                new Decision($verdict, $basis, 'decided', [], $remedies, $indeterminate);
                self::fail(\sprintf('A decision was made of %s.', $what));
            } catch (IntegratorError $error) {
                self::assertSame(\sprintf('A %s decision can\'t have these parts.', $verdict->value), $error->problem);
            }
        }

        self::assertSame([$remedy], Decision::deny('Wait.', remedies: [$remedy])->remedies);
    }
}
