<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A policy element that reads for many requests at once (AUZ-032): before a batch is decided, it's
 * given the batch's requests that it matches, and reads what they need in as few queries as it can,
 * however many there are. Each request is then evaluated as on its own, with what was read.
 *
 * What it reads is for those requests only: kept by request, never past them. If preloading fails,
 * each request is evaluated as if it hadn't been tried, and reports what it can't read itself.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application uses it directly: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
interface Preloads
{
    /**
     * @param list<Request> $requests
     */
    public function preload(array $requests): void;
}
