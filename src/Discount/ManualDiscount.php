<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Discount;

/**
 * An order-level manual discount is stored as a neutral order adjustment (the "request": amount
 * and internal reason, not counted in the totals) and distributed by the
 * ManualDiscountProcessor on every recalculation into regular order promotion adjustments on
 * the units, so that taxes, totals, e-mails and the shop pages handle it like any promotion.
 */
final class ManualDiscount
{
    /** Neutral order adjustment holding the requested amount (negative) and the reason (label). */
    public const REQUEST_ADJUSTMENT_TYPE = 'tmonier_admin_order_manual_discount';

    /** Origin code of the distributed order promotion adjustments. */
    public const ORIGIN_CODE = 'tmonier_admin_order_manual_discount';

    public const REASON_MAX_LENGTH = 255;

    private function __construct()
    {
    }
}
