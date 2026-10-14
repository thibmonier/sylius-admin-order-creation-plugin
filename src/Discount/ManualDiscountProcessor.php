<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Discount;

use Sylius\Component\Core\Distributor\ProportionalIntegerDistributorInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

/**
 * Runs after the promotions (priority 20) and before the taxes (priority 10): spreads the
 * requested manual discount over the items, proportionally to their total, then over their units.
 * The discount never exceeds the items total. Orders without a request are left untouched.
 */
final readonly class ManualDiscountProcessor implements OrderProcessorInterface
{
    /** @param AdjustmentFactoryInterface<AdjustmentInterface> $adjustmentFactory */
    public function __construct(
        private AdjustmentFactoryInterface $adjustmentFactory,
        private ProportionalIntegerDistributorInterface $distributor,
        private TranslatorInterface $translator,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        if (OrderInterface::STATE_CART !== $order->getState()) {
            return;
        }

        $requests = $order->getAdjustments(ManualDiscount::REQUEST_ADJUSTMENT_TYPE);
        if ($requests->isEmpty()) {
            return;
        }

        $this->removeDistributedAdjustments($order);

        $requestedAmount = 0;
        foreach ($requests as $request) {
            $requestedAmount += abs($request->getAmount());
        }

        $items = array_values($order->getItems()->toArray());
        $itemTotals = array_map(static fn (\Sylius\Component\Core\Model\OrderItemInterface $item): int => max(0, $item->getTotal()), $items);
        $amount = min($requestedAmount, array_sum($itemTotals));
        if ($amount <= 0) {
            return;
        }

        $label = $this->translator->trans('tmonier_admin_order.ui.manual_discount_label', [], 'messages', $order->getLocaleCode());
        $itemAmounts = $this->distributor->distribute($itemTotals, -$amount);
        foreach ($items as $index => $item) {
            if (0 === $itemAmounts[$index]) {
                continue;
            }

            $units = array_values($item->getUnits()->toArray());
            $unitTotals = array_map(static fn (\Sylius\Component\Order\Model\OrderItemUnitInterface $unit): int => max(0, $unit->getTotal()), $units);
            if (array_sum($unitTotals) <= 0) {
                continue;
            }

            $unitAmounts = $this->distributor->distribute($unitTotals, $itemAmounts[$index]);
            foreach ($units as $unitIndex => $unit) {
                if (0 === $unitAmounts[$unitIndex]) {
                    continue;
                }

                $adjustment = $this->adjustmentFactory->createWithData(
                    AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT,
                    $label,
                    $unitAmounts[$unitIndex],
                );
                $adjustment->setOriginCode(ManualDiscount::ORIGIN_CODE);
                $unit->addAdjustment($adjustment);
            }
        }
    }

    private function removeDistributedAdjustments(OrderInterface $order): void
    {
        foreach ($order->getItems() as $item) {
            foreach ($item->getUnits() as $unit) {
                foreach ($unit->getAdjustments(AdjustmentInterface::ORDER_PROMOTION_ADJUSTMENT) as $adjustment) {
                    if (ManualDiscount::ORIGIN_CODE === $adjustment->getOriginCode()) {
                        $unit->removeAdjustment($adjustment);
                    }
                }
            }
        }
    }
}
