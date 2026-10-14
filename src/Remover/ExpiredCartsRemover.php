<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Remover;

use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ObjectManager;
use Sylius\Bundle\OrderBundle\SyliusExpiredCartsEvents;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Remover\ExpiredCartsRemoverInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Replaces the core remover of `sylius:remove-expired-carts`: same behaviour and events, but
 * the admin drafts are left out, their lifetime is handled by tmonier:admin-order:purge-drafts.
 */
final readonly class ExpiredCartsRemover implements ExpiredCartsRemoverInterface
{
    /** @param EntityRepository<OrderInterface> $orderRepository */
    public function __construct(
        private EntityRepository $orderRepository,
        private ObjectManager $orderManager,
        private EventDispatcherInterface $eventDispatcher,
        private string $expirationPeriod,
        private string $adminOrderCreationClass,
        private int $batchSize = 100,
    ) {
    }

    public function remove(): void
    {
        while ([] !== $expiredCarts = $this->getBatch()) {
            foreach ($expiredCarts as $expiredCart) {
                $this->orderManager->remove($expiredCart);
            }

            $this->eventDispatcher->dispatch(new GenericEvent($expiredCarts), SyliusExpiredCartsEvents::PRE_REMOVE);
            $this->orderManager->flush();
            $this->eventDispatcher->dispatch(new GenericEvent($expiredCarts), SyliusExpiredCartsEvents::POST_REMOVE);
            $this->orderManager->clear();
        }
    }

    /** @return list<OrderInterface> */
    private function getBatch(): array
    {
        /** @var list<OrderInterface> $carts */
        $carts = $this->orderRepository->createQueryBuilder('o')
            ->andWhere('o.state = :state')
            ->andWhere('o.updatedAt < :terminalDate')
            ->andWhere(sprintf('NOT EXISTS (SELECT draft.id FROM %s draft WHERE draft.order = o)', $this->adminOrderCreationClass))
            ->setParameter('state', OrderInterface::STATE_CART)
            ->setParameter('terminalDate', new \DateTime(sprintf('-%s', $this->expirationPeriod)))
            ->setMaxResults($this->batchSize)
            ->getQuery()
            ->getResult()
        ;

        return $carts;
    }
}
