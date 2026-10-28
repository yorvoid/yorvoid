<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Secret;

/**
 * The request a flow continues in: who is signed in, and what steps that sign people in record about
 * where they came from.
 *
 * @api
 */
final readonly class FlowContext
{
    /**
     * @param Session|null $session      The request's YorvoID session, if it's valid
     * @param string|null  $device       What the person would recognise their device by, e.g. the browser's description
     * @param string|null  $address      The client's IP address, so attempts from one address are limited
     * @param Secret|null  $deviceCookie The cookie that recognises the device, if the browser sent one
     * @param string       $locale       The language to prepare things in, e.g. an operation's summary
     */
    public function __construct(
        public Origin $origin,
        public ?Session $session = null,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
        public string $locale = 'en',
    ) {
    }

    public function withSession(?Session $session): self
    {
        return new self($this->origin, $session, $this->device, $this->address, $this->deviceCookie, $this->locale);
    }
}
