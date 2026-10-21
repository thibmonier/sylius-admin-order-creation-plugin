<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit\Address\Double;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Address\OrderAddressSourceInterface;

final readonly class StaticAddressSource implements OrderAddressSourceInterface
{
    /** @param array<int|string, AddressInterface> $addresses */
    public function __construct(
        private string $code,
        private string $label,
        private array $addresses,
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAddresses(OrderInterface $order): array
    {
        return $this->addresses;
    }
}
