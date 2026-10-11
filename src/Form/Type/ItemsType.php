<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Quantities (0 removes the item) and, when allowed, custom unit prices of the draft items.
 * One "item_<id>" sub-form per order item.
 */
final class ItemsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var OrderInterface $order */
        $order = $options['order'];

        foreach ($order->getItems() as $item) {
            $itemId = $item->getId();
            if (!is_int($itemId)) {
                continue;
            }

            $itemForm = $builder->create(self::fieldName($itemId), FormType::class, ['label' => false])
                ->add('quantity', IntegerType::class, [
                    'label' => 'sylius.ui.quantity',
                    'data' => $item->getQuantity(),
                    'attr' => ['min' => 0],
                    'constraints' => [new NotNull(), new Range(min: 0, max: 9999)],
                ])
            ;

            if (true === $options['allow_price_override']) {
                $itemForm->add('unitPrice', MoneyType::class, [
                    'label' => 'tmonier_admin_order.form.custom_unit_price',
                    'required' => false,
                    'currency' => $order->getCurrencyCode(),
                    'data' => $item->isImmutable() ? $item->getUnitPrice() : null,
                    'constraints' => [new PositiveOrZero()],
                ]);
            }

            $builder->add($itemForm);
        }
    }

    public static function fieldName(int $itemId): string
    {
        return sprintf('item_%d', $itemId);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('order')
            ->setAllowedTypes('order', OrderInterface::class)
            ->setDefault('allow_price_override', false)
            ->setAllowedTypes('allow_price_override', 'bool')
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_items';
    }
}
