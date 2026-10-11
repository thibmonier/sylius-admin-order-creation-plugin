<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

final class CouponType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => 'sylius.ui.coupon',
            'required' => false,
            'help' => 'tmonier_admin_order.form.coupon_help',
            'constraints' => [new Length(max: 255)],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_coupon';
    }
}
