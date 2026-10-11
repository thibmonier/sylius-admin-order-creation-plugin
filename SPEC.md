# SPEC: Admin order creation for Sylius 2

## 1. Research

### Does Sylius 2 already do it?

No, checked on 2026-10-11 against `sylius/sylius` v2.3.0:

- `AdminBundle/Resources/config/routing/order.yml`: the `sylius_admin_order` resource only
  declares `only: ['index']`, plus show, history, update (shipping address), cancel, payment
  complete/refund, shipment ship and resend confirmation. No create route, template or hook.
- The admin API has no order creation either (only shop carts via `/api/v2/shop/orders`).
- `CHANGELOG-2.0` to `CHANGELOG-2.3` and `UPGRADE-2.x` mention no admin order creation.
- Sylius Plus (paid) ships "Admin order creation"; that is out of scope for the free edition.

### The Sylius 1 plugin

`Sylius/AdminOrderCreationPlugin` (packagist `sylius/admin-order-creation-plugin`, ~694k
downloads, MIT) is archived and requires Sylius 1.x; forks (`tulik/...`, `fmdd/...`) stop at
1.14 (<https://github.com/Sylius/AdminOrderCreationPlugin>,
<https://packagist.org/packages/tulik/admin-order-creation-plugin>). Its features, used as a
checklist only (no code reused, Sylius 2 differs: Twig Hooks, Symfony Workflow, Tabler admin):
channel / locale / currency selection, customer selection, item and order discounts as a new
adjustment type, reorder (with pluggable reorder processors), payment link e-mail through a
listener.

### How the shop builds an order (Sylius 2.3 core)

- Cart creation, `ShopBasedCartContext`: channel, base currency of the channel, locale,
  customer (`setCustomerWithAuthorization` when the customer is logged in), default address
  cloned as billing address.
- Items: order item factory, `OrderItemQuantityModifier` (units), `OrderModifier::addToOrder`
  (merges equal variants and processes the cart).
- `sylius.order_processing.order_processor` (composite, priorities): unit adjustments
  preloader 70, adjustments clearer 60 (promotion, shipping, tax types), prices recalculator
  50 (skips `immutable` items), shipments 40, shipping charges 30, promotions 20, taxes 10,
  payment 0. Every processor returns early when the order is not a cart.
- Checkout, Symfony workflow `sylius_order_checkout`: `address` → `select_shipping` /
  `skip_shipping` → `select_payment` / `skip_payment` → `complete`. Listeners
  (`CoreBundle/Resources/config/services/listeners/workflow/order_checkout.php`): process cart
  after each step, `CheckoutStateResolver` skips the steps that are not required, `complete`
  saves the completion date and applies `sylius_order.create`, whose listeners assign number
  and token, create payments and shipments, hold inventory, increment promotion usage, save
  customer addresses.
- Validation of the last shop step: groups `sylius_checkout_complete` (product, shipping and
  payment method eligibility, `InStock`).
- Confirmation e-mail: `sylius.mailer.order_email_manager` (`ShopBundle` sends it on
  `sylius.order.post_complete`, an event of the resource controller, not of the workflow, so a
  programmatic completion sends nothing unless asked).
- Customer cart lookup: `OrderRepository::findLatestNotEmptyCartByChannelAndCustomer` only
  returns carts with `createdByGuest = false`.
- Pay later: shop `sylius_shop_order_show` (`/order/{tokenValue}`) lets the customer pick a
  payment method and redirects to `sylius_shop_order_pay`; it is the link of the core
  confirmation e-mail too.

## 2. Design decisions

| Topic | Decision | Why |
|---|---|---|
| Draft storage | A regular cart + a row in `tmonier_admin_order_creation` (order 1-1, admin, admin name snapshot, source order, completion date, e-mail flags) | Never modify core entities; the cart goes through the real processor and checkout. |
| Hide drafts from the shop | Keep `createdByGuest = true` | The core customer cart lookup ignores such carts: no decoration needed, no extra query. Session carts are not involved. |
| Price override | `unitPrice` + core `immutable` flag | The recalculator skips immutable items; `originalUnitPrice` keeps the catalog price. |
| Manual discount | Neutral order adjustment (request: amount + reason) distributed by a processor (priority 15) into `order_promotion` unit adjustments, origin code `tmonier_admin_order_manual_discount`, label "Discount" in the order locale | Taxes are computed on discounted units, totals / e-mails / shop / invoices treat it like a promotion; the reason stays internal; no query added to shop cart processing. Capped to the items total. |
| Permissions | Voter with two attributes, config flags + configurable role | Sylius CE has no RBAC; integrators can swap the voter. |
| UI | Classic multi-step pages (POST/redirect), Twig Hooks layout, Symfony UX autocomplete for customers and variants (route `sylius_admin_entity_autocomplete`) | Robust and testable with WebTestCase; Live Components rejected (more moving parts, no gain for a 4-step flow). |
| Methods | Choices from `sylius.resolver.shipping_methods` / `sylius.resolver.payment_methods`, fee from the shipping calculator registry | Same eligibility as the shop. |
| Completion | Workflow `complete` + flush, then e-mails, then event | Same side effects as the shop; mail failures only warn. |
| Payment link | Own Sylius mailer e-mail to `sylius_shop_order_show` via `sylius_channel_url` | Reuses the shop pay flow, no gateway code. |
| Reorder | New draft, same channel/customer/locale (fallback to channel default), copies of addresses, items still sold at current prices | Coupons and custom prices are deliberate one-offs. |
| Purge | Command over drafts whose order `updatedAt` is older than N days, batches of 100 | Explicit, schedulable; DB FK cascade also cleans the row when core removes expired carts. |

## 3. Verification

- Integration test `ShopParityTest`: the same order (two variants, coupon promotion, extra
  10% tax rate, GB address, DHL, bank transfer) is placed through the shop API and through the
  plugin; totals, taxes, promotions, states, payments, shipments, coupon usage and inventory
  hold are identical, numbers differ, the event is dispatched once.
- Functional tests cover the UI flow, permissions, CSRF, coupons, reorder, e-mails, drafts.

## 4. Out of scope (v1)

Sylius Plus RBAC, editing placed orders, multi-currency display, live component cart, per-item
discount reasons, B2B price lists.
