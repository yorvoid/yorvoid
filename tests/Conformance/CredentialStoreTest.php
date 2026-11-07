<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Credential\CredentialStore;
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
}
