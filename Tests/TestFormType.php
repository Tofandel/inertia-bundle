<?php

namespace Rompetomp\InertiaBundle\Tests;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationRequestHandler;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class TestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('last_name', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank(), new Length(['min' => 3])],
            ])
            ->add('first_name', TextType::class, [
                'required' => true,
                'constraints' => [new NotBlank(), new Length(['min' => 10])], ])
            ->add('emails', CollectionType::class, [
                // each entry in the array will be an "email" field
                'entry_type' => EmailType::class,
                'allow_add' => true, // This should do the trick.
                'entry_options' => [
                    'constraints' => [new Email()],
                ],
            ]
            )
            ->add('phone', TextType::class, [
                'required' => false,
            ]);

        $builder->setRequestHandler(new HttpFoundationRequestHandler());
    }
}
