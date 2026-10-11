<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Sylius\Bundle\AddressingBundle\Form\Type\AddressType;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * Billing and shipping addresses, the Sylius address form for each. Data:
 * array{billingAddress: AddressInterface, shippingAddress: AddressInterface, differentShippingAddress: bool}.
 * Unless "different shipping address" is checked, the billing address is used for shipping.
 */
final class AddressStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('billingAddress', AddressType::class, [
                'label' => 'sylius.ui.billing_address',
                'channel' => $options['channel'],
                'constraints' => [new Valid()],
            ])
            ->add('differentShippingAddress', CheckboxType::class, [
                'label' => 'tmonier_admin_order.form.different_shipping_address',
                'required' => false,
            ])
            ->add('shippingAddress', AddressType::class, [
                'label' => 'sylius.ui.shipping_address',
                'channel' => $options['channel'],
                'shippable' => true,
                'constraints' => [new Valid()],
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (!is_array($data) || !empty($data['differentShippingAddress'])) {
                    return;
                }

                if (isset($data['billingAddress'])) {
                    $data['shippingAddress'] = $data['billingAddress'];
                    $event->setData($data);
                }
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('channel')
            ->setAllowedTypes('channel', ChannelInterface::class)
            ->setDefault('validation_groups', static fn (FormInterface $form): array => ['sylius'])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_address';
    }
}
