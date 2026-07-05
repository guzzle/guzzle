<?php

declare(strict_types=1);

namespace GuzzleHttp\Auth;

/**
 * @internal
 */
final class DigestChallenge
{
    /** @var array{name: string, hash: string, sess: bool, rank: int, header: string} */
    public array $algorithm;

    public string $realm = '';

    public string $nonce;

    public ?string $opaque = null;

    /** @var list<string> */
    public array $domain = [];

    public ?string $qop = null;

    public bool $stale = false;

    public bool $userhash = false;
}
