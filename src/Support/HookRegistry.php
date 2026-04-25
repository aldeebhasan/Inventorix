<?php

namespace Aldeebhasan\Inventorix\Support;

class HookRegistry
{
    /** @var array<string, callable[]> */
    private array $hooks = [
        'beforeAdd' => [],
        'afterAdd' => [],
        'beforeDeduct' => [],
        'afterDeduct' => [],
    ];

    public function register(string $hook, callable $handler): void
    {
        $this->hooks[$hook][] = $handler;
    }

    public function run(string $hook, mixed ...$args): void
    {
        foreach ($this->hooks[$hook] ?? [] as $handler) {
            $handler(...$args);
        }
    }

    public function flush(): void
    {
        foreach (array_keys($this->hooks) as $key) {
            $this->hooks[$key] = [];
        }
    }
}
