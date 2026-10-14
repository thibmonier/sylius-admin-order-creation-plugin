<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Form;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\CustomerAutocompleteType;

final class CustomerAutocompleteTypeTest extends TestCase
{
    public function testTheLabelShowsTheNameAndTheEmailWithoutHtmlSpecialCharacters(): void
    {
        // The Sylius admin escapes autocomplete labels on top of Symfony UX: "<" and ">" would show as "&lt;" and "&gt;".
        $customer = new Customer();
        $customer->setFirstName('Gina');
        $customer->setLastName('Brakus');
        $customer->setEmail('gina@example.com');

        self::assertSame('Gina Brakus (gina@example.com)', CustomerAutocompleteType::label($customer));
    }

    public function testTheLabelFallsBackToTheEmailWithoutAName(): void
    {
        $customer = new Customer();
        $customer->setEmail('gina@example.com');

        self::assertSame('gina@example.com', CustomerAutocompleteType::label($customer));
    }
}
