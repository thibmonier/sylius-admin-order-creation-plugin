<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Address;

use Sylius\Component\Core\Model\AddressInterface;

/** The non-empty addresses of one source, indexed by their "source:key" reference. */
final readonly class AddressGroup
{
    /** @param array<string, AddressInterface> $addresses */
    public function __construct(
        public string $code,
        public string $label,
        public array $addresses,
    ) {
    }
}
