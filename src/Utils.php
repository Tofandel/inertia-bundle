<?php

namespace Rompetomp\InertiaBundle;

use Symfony\Component\HttpFoundation\Request;

abstract class Utils
{

    public static function isInertiaRequest(Request $request): bool
    {
        return $request?->headers?->has('X-Inertia');
    }
}