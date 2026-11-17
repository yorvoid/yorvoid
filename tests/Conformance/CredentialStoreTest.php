<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Credential\Storage\DbalCredentialStore;
use YorvoID\Testing\Contract\CredentialStoreContract;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Every CredentialStore keeps the same promises (PLT-025).
 */
final class CredentialStoreTest extends DatabaseTestCase
{
    use CredentialStoreContract;

    protected function credentialStore(string $implementation): CredentialStore
    {
        return $this->stackOf($implementation)->credentials;
    }

    protected function sameCredentialStore(string $implementation, CredentialStore $store): CredentialStore
    {
        return 'memory' === $implementation ? $store : new DbalCredentialStore($this->database($implementation));
    }
}
