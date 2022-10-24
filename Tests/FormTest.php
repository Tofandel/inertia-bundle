<?php

namespace Rompetomp\InertiaBundle\Tests;

use Rompetomp\InertiaBundle\EventListener\InertiaListener;
use Rompetomp\InertiaBundle\Utils;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\Validation;

class FormTest extends TypeTestCase
{
    public function testProcessErrors()
    {
        $request = new Request([], [
            'test_form' => [
                'first_name' => 'test',
                'emails' => ['abc@test.com', 'foo'],
            ],
        ]);
        $request->setMethod('POST');

        $request->setSession(new Session(new MockArraySessionStorage()));

        $form = $this->factory->create(TestFormType::class);

        $res = Utils::processForm($form, $request);

        $this->assertFalse($res);

        $errors = InertiaListener::resolveValidationErrors($request);

        $this->assertEquals((object)[
            "test_form.last_name" => "This value should not be blank.",
            "test_form.first_name" => "This value is too short. It should have 10 characters or more.",
            "test_form.emails.1" => "This value is not a valid email address.",
        ], $errors);

        $errors = InertiaListener::resolveValidationErrors($request);
        $this->assertEquals((object)[], $errors);
    }

    protected function getExtensions()
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }
}