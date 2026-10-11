<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscount;

final class ManualDiscountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', MoneyType::class, [
                'label' => 'tmonier_admin_order.form.discount_amount',
                'required' => false,
                'currency' => $options['currency'],
                'help' => 'tmonier_admin_order.form.discount_amount_help',
                'constraints' => [new PositiveOrZero()],
            ])
            ->add('reason', TextType::class, [
                'label' => 'tmonier_admin_order.form.discount_reason',
                'required' => false,
                'help' => 'tmonier_admin_order.form.discount_reason_help',
                'constraints' => [new Length(max: ManualDiscount::REASON_MAX_LENGTH)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('currency')
            ->setAllowedTypes('currency', 'string')
            ->setDefault('constraints', [new Callback(self::validate(...))])
        ;
    }

    public static function validate(mixed $data, ExecutionContextInterface $context): void
    {
        if (!is_array($data) || (int) ($data['amount'] ?? 0) <= 0) {
            return;
        }

        if ('' === trim((string) ($data['reason'] ?? ''))) {
            $context->buildViolation('tmonier_admin_order.discount.reason_required')->atPath('[reason]')->addViolation();
        }
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_manual_discount';
    }
}
