<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField(
    alias: 'tmonier_admin_order_customer',
    route: 'sylius_admin_entity_autocomplete',
)]
final class CustomerAutocompleteType extends AbstractType
{
    public function __construct(
        private readonly string $customerClass,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => $this->customerClass,
            'searchable_fields' => ['email', 'firstName', 'lastName'],
            'choice_label' => self::label(...),
            'security' => 'ROLE_ADMINISTRATION_ACCESS',
            'max_results' => 20,
        ]);
    }

    public static function label(CustomerInterface $customer): string
    {
        $name = trim($customer->getFullName());

        return '' === $name ? (string) $customer->getEmail() : sprintf('%s (%s)', $name, (string) $customer->getEmail());
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_customer_autocomplete';
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
