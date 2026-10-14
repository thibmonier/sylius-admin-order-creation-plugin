<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\LocaleBundle\Form\Type\LocaleChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Model\StartOrderData;

final class StartOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', ChannelChoiceType::class, [
                'label' => 'sylius.ui.channel',
                'constraints' => [new NotNull(message: 'tmonier_admin_order.channel.not_blank')],
            ])
            ->add('locale', LocaleChoiceType::class, [
                'label' => 'tmonier_admin_order.form.locale',
                'required' => false,
                'placeholder' => 'tmonier_admin_order.form.locale_placeholder',
                'help' => 'tmonier_admin_order.form.locale_help',
            ])
            ->add('customer', CustomerAutocompleteType::class, [
                'label' => 'tmonier_admin_order.form.existing_customer',
                'required' => false,
                'placeholder' => 'tmonier_admin_order.form.search_customer',
            ])
            ->add('email', EmailType::class, [
                'label' => 'sylius.ui.email',
                'required' => false,
                'constraints' => [new Email(), new Length(max: 255)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'sylius.ui.first_name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'sylius.ui.last_name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'sylius.ui.phone_number',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StartOrderData::class,
            'constraints' => [new Callback(self::validate(...))],
        ]);
    }

    public static function validate(mixed $data, ExecutionContextInterface $context): void
    {
        if (!$data instanceof StartOrderData) {
            return;
        }

        $email = trim((string) $data->email);
        if (!$data->customer instanceof \Sylius\Component\Core\Model\CustomerInterface && '' === $email) {
            $context->buildViolation('tmonier_admin_order.customer.required')->atPath('customer')->addViolation();
        }
        if ($data->customer instanceof \Sylius\Component\Core\Model\CustomerInterface && '' !== $email) {
            $context->buildViolation('tmonier_admin_order.customer.ambiguous')->atPath('email')->addViolation();
        }

        if ($data->channel instanceof \Sylius\Component\Core\Model\ChannelInterface && $data->locale instanceof \Sylius\Component\Locale\Model\LocaleInterface && !$data->channel->hasLocale($data->locale)) {
            $context->buildViolation('tmonier_admin_order.locale.not_in_channel')->atPath('locale')->addViolation();
        }
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_start';
    }
}
