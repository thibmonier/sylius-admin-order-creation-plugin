<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit;

use Sylius\Resource\Factory\FactoryInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * @template T of ResourceInterface
 * @implements FactoryInterface<T>
 */
final class InMemoryFactory implements FactoryInterface
{
    /**
     * @param class-string<T> $type
     * @param \Closure(): T $create
     */
    public function __construct(public readonly string $type, private readonly \Closure $create)
    {
    }

    public function createNew(): ResourceInterface
    {
        return ($this->create)();
    }
}
