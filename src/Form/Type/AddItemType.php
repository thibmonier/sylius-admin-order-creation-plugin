<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

final class AddItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('variant', ProductVariantAutocompleteType::class, [
                'label' => 'tmonier_admin_order.form.product',
                'placeholder' => 'tmonier_admin_order.form.search_product',
                'extra_options' => ['channel' => $options['channel_code']],
                'constraints' => [new NotNull(message: 'tmonier_admin_order.item.variant_required')],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'sylius.ui.quantity',
                'data' => 1,
                'attr' => ['min' => 1],
                'constraints' => [new NotNull(), new Range(min: 1, max: 9999)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('channel_code');
        $resolver->setAllowedTypes('channel_code', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_add_item';
    }
}
