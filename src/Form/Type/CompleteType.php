<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

final class CompleteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('notes', TextareaType::class, [
                'label' => 'tmonier_admin_order.form.notes',
                'required' => false,
                'help' => 'tmonier_admin_order.form.notes_help',
                'constraints' => [new Length(max: 1000)],
                'attr' => ['rows' => 3],
            ])
            ->add('sendConfirmationEmail', CheckboxType::class, [
                'label' => 'tmonier_admin_order.form.send_confirmation_email',
                'required' => false,
                'data' => $options['send_confirmation_email'],
            ])
        ;

        if (true === $options['can_send_payment_link']) {
            $builder->add('sendPaymentLink', CheckboxType::class, [
                'label' => 'tmonier_admin_order.form.send_payment_link',
                'help' => 'tmonier_admin_order.form.send_payment_link_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'send_confirmation_email' => true,
                'can_send_payment_link' => false,
            ])
            ->setAllowedTypes('send_confirmation_email', 'bool')
            ->setAllowedTypes('can_send_payment_link', 'bool')
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_complete';
    }
}
