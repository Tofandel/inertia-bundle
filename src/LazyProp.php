<?php

namespace Rompetomp\InertiaBundle;

class LazyProp
{
    protected $callback;

    public function __construct(callable|string|array $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke()
    {
        return call_user_func($this->callback);
    }
}