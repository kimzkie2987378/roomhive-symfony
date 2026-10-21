<?php

namespace App\Form;

use App\Entity\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ContactMessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label'       => 'First Name',
                'constraints' => [new Assert\NotBlank(message: 'Please enter your first name.')],
            ])
            ->add('lastName', TextType::class, [
                'label'       => 'Last Name',
                'constraints' => [new Assert\NotBlank(message: 'Please enter your last name.')],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'Email Address',
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter your email address.'),
                    new Assert\Email(message: 'Please enter a valid email address.'),
                ],
            ])
            ->add('subject', TextType::class, [
                'label'       => 'Subject',
                'constraints' => [new Assert\NotBlank(message: 'Please enter a subject.')],
            ])
            ->add('message', TextareaType::class, [
                'label'       => 'Your Message',
                'constraints' => [new Assert\NotBlank(message: 'Please enter a message.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactMessage::class,
        ]);
    }
}
