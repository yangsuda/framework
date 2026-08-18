<?php
/**
 * 会话管理类
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Core;

class Session
{
    private bool $started = false;
    private array $data = [];

    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->started = true;
        $this->data = &$_SESSION; // 引用绑定，改动实时生效
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }
}
