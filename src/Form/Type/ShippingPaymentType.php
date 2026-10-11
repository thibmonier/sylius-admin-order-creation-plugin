<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One "shipment_<index>" choice per shipment, among the methods the core resolvers support for
 * it, and the payment method. Choice labels are computed by the controller (name and fee).
 */
final class ShippingPaymentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<int, array{methods: list<ShippingMethodInterface>, labels: array<string, string>, selected: ShippingMethodInterface|null}> $shipments */
        $shipments = $options['shipments'];
        foreach ($shipments as $index => $shipment) {
            $labels = $shipment['labels'];
            $builder->add(self::shipmentFieldName($index), ChoiceType::class, [
                'label' => count($shipments) > 1 ? 'tmonier_admin_order.form.shipment_method' : 'sylius.ui.shipping_method',
                'label_translation_parameters' => ['%number%' => $index + 1],
                'choices' => $shipment['methods'],
                'choice_value' => static fn (?ShippingMethodInterface $method): ?string => $method?->getCode(),
                'choice_label' => static fn (ShippingMethodInterface $method): string => $labels[(string) $method->getCode()] ?? (string) $method->getName(),
                'choice_translation_domain' => false,
                'expanded' => true,
                'data' => $shipment['selected'],
                'constraints' => [new NotNull(message: 'tmonier_admin_order.shipping_method.required')],
            ]);
        }

        if (null !== $options['payment_methods']) {
            $builder->add('paymentMethod', ChoiceType::class, [
                'label' => 'sylius.ui.payment_method',
                'choices' => $options['payment_methods'],
                'choice_value' => static fn (?PaymentMethodInterface $method): ?string => $method?->getCode(),
                'choice_label' => static fn (PaymentMethodInterface $method): string => (string) $method->getName(),
                'choice_translation_domain' => false,
                'expanded' => true,
                'data' => $options['selected_payment_method'],
                'constraints' => [new NotNull(message: 'tmonier_admin_order.payment_method.required')],
            ]);
        }
    }

    public static function shipmentFieldName(int $index): string
    {
        return sprintf('shipment_%d', $index);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'shipments' => [],
                'payment_methods' => null,
                'selected_payment_method' => null,
            ])
            ->setAllowedTypes('shipments', 'array')
            ->setAllowedTypes('payment_methods', ['null', 'array'])
            ->setAllowedTypes('selected_payment_method', ['null', PaymentMethodInterface::class])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_shipping_payment';
    }
}
