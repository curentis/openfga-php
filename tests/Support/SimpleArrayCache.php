<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\SimpleCache\CacheInterface;

final class SimpleArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $expiry = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->values[$key];
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;
        if ($ttl === null) {
            unset($this->expiry[$key]);

            return true;
        }

        $seconds = is_int($ttl) ? $ttl : 60;
        $this->expiry[$key] = time() + $seconds;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->values[$key], $this->expiry[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->values = [];
        $this->expiry = [];

        return true;
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $cached = $this->get($key, $default);
            $result[$key] = $cached;
        }

        return $result;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $this->set($key, $value, $ttl); // $value is mixed per CacheInterface
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->values)) {
            return false;
        }

        if (!isset($this->expiry[$key])) {
            return true;
        }

        if (time() >= $this->expiry[$key]) {
            unset($this->values[$key], $this->expiry[$key]);

            return false;
        }

        return true;
    }
}
