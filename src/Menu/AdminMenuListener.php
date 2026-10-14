<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/** Adds "Draft orders" to the "Sales" section of the admin menu. */
final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $sales = $event->getMenu()->getChild('sales');
        if (!$sales instanceof \Knp\Menu\ItemInterface) {
            return;
        }

        $sales
            ->addChild('tmonier_admin_order_drafts', ['route' => 'tmonier_admin_order_draft_index'])
            ->setLabel('tmonier_admin_order.ui.draft_orders')
            ->setLabelAttribute('icon', 'tabler:file-pencil')
        ;
    }
}
