<?php

namespace Rompetomp\InertiaBundle;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

abstract class Utils
{
    public static function isInertiaRequest(?Request $request): bool
    {
        return $request?->headers?->has('X-Inertia') ?? false;
    }

    public static function processForm(FormInterface $form, Request $request): bool
    {
        $form->handleRequest($request);

        if (!$form->isValid()) {
            $bag = new MessageBag();

            foreach ($form->getErrors(true, true) as $error) {
                $path = '';
                $parent = $error->getOrigin();
                while ($parent) {
                    $path = $parent->getName().'.'.$path;
                    $parent = $parent->getParent();
                }
                $path = trim($path, '.');
                $bag->add($path, $error->getMessage());
            }

            $sb = new ViewErrorBag();
            $sb->put('default', $bag);

            $request->getSession()->set('errors', $sb);

            return false;
        }

        return true;
    }
}
