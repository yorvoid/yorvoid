<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Realm;

require __DIR__.'/bootstrap.php';

$config->setTarget(onlyRefusals(static function (string $input): void {
    // Any JSON object becomes details; anything else is used as a single detail and as the subject.
    $details = json_decode($input, true, 16);
    $details = is_array($details) && !array_is_list($details) ? $details : ['value' => $input];
    $subject = '' === $input ? null : substr($input, 0, 191);

    $entry = LogEntry::create(Realm::default(), 1, Uuid::v7(), new DateTimeImmutable(), 'yorvoid.fuzz.details', Outcome::Success, new Origin(OriginKind::Web, $input), null, 'fuzz', $subject, $details, 'previous');

    if ($entry->computeHash() !== $entry->hash) {
        throw new Error('A created entry doesn\'t match its own hash.');
    }
}));
$config->setMaxLen(512);
