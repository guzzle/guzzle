<?php

declare(strict_types=1);

namespace GuzzleHttp;

final class ProxySelection
{
    private ?string $proxy;

    private bool $bypassed;

    private bool $disabled;

    private function __construct(?string $proxy, bool $bypassed, bool $disabled)
    {
        $this->proxy = $proxy;
        $this->bypassed = $bypassed;
        $this->disabled = $disabled;
    }

    public static function none(): self
    {
        return new self(null, false, false);
    }

    public static function proxy(string $proxy): self
    {
        return $proxy === '' ? self::disabled() : new self($proxy, false, false);
    }

    public static function bypassed(): self
    {
        return new self(null, true, false);
    }

    public static function disabled(): self
    {
        return new self(null, false, true);
    }

    public function getProxy(): ?string
    {
        return $this->proxy;
    }

    public function hasProxy(): bool
    {
        return $this->proxy !== null;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function shouldDisableProxy(): bool
    {
        return $this->bypassed || $this->disabled;
    }
}
