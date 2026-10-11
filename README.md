# Sylius Admin Order Creation Plugin

[![Build](https://github.com/thibmonier/sylius-admin-order-creation-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-admin-order-creation-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Create orders on behalf of customers (phone and back-office orders) from the Sylius 2 admin panel.**

Sylius 2 has no way to create an order in the back office: the admin order section only lists,
shows and cancels orders placed in the shop. The official `sylius/admin-order-creation-plugin`
was archived at Sylius 1.x, and the feature is otherwise only part of the paid Sylius Plus.

This plugin lets an administrator build a cart for a customer, step by step, and place it
through the **same checkout as the shop**: same order processor (prices, promotions, coupons,
shipping, taxes), same checkout state machine, same order number, inventory hold, payment and
shipping states and confirmation e-mail. Free and open source.

## Features

| Need | What the plugin does |
|---|---|
| Take an order on the phone | **Create order** button on the orders list and on the customer page (customer preselected). Choose the channel and language, then an existing customer (search by e-mail or name) or a new guest customer by e-mail. |
| Build the cart | Search products by name or code (variants of the channel), quantities, live totals recalculated by the Sylius order processor (catalog and cart promotions, coupon, shipping, taxes). |
| Commercial gestures | Optional **custom unit price** per item and **manual order discount** with an internal reason, both behind a role and switchable off in the configuration. The discount is spread over the units like an order promotion, so taxes, invoices, e-mails and the shop pages handle it. |
| Addresses | Prefilled with the customer's default address, one click to use any address of the address book, separate shipping address when needed. |
| Shipping and payment | Only the methods the core resolvers offer for the order (channel, zone, eligibility), with the shipping fee. Steps the shop would skip are skipped. |
| Place the order | Summary, checkout validation of the shop (stock, eligibility), notes, **order confirmation e-mail on/off**, optional **payment link e-mail**: the customer pays from the shop's order page (`/order/{token}`, the standard "pay again" flow, any payment gateway). |
| Reorder | **Reorder** action on the order page: a new draft with the same items (still sold, current prices), addresses and customer. |
| Know who did what | "Created by: Back office, by *admin name*" on the order page, plus the source order of a reorder and the manual discount reason. |
| Unfinished drafts | **Draft orders** list (Sales menu): continue or delete. Drafts are never shown to the customer as their shop cart. `tmonier:admin-order:purge-drafts` deletes abandoned drafts. |
| Integrations | `tmonier_admin_order.order_created` event once an order is placed. |

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish;
Twig Hooks; MySQL, MariaDB and PostgreSQL support.

## Screenshots

Screenshots go in `docs/images/`.

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2, 2.3 |
| Symfony | 6.4, 7.4, 8.0 |

The checkout must use the **Symfony Workflow** state machine adapter (the Sylius 2 default).

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-admin-order-creation-plugin
    ```

2. Register the bundle in `config/bundles.php` (Symfony Flex does it automatically once a recipe exists):

    ```php
    Tmonier\SyliusAdminOrderCreationPlugin\TmonierSyliusAdminOrderCreationPlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_admin_order_creation.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusAdminOrderCreationPlugin/config/config.yaml" }
    ```

4. Import the admin routes in `config/routes/tmonier_sylius_admin_order_creation.yaml`:

    ```yaml
    tmonier_sylius_admin_order_creation:
        resource: "@TmonierSyliusAdminOrderCreationPlugin/config/routes.yaml"
    ```

5. Run the migrations:

    ```bash
    bin/console doctrine:migrations:migrate
    ```

6. Schedule the purge of abandoned drafts (daily, for instance):

    ```bash
    bin/console tmonier:admin-order:purge-drafts            # drafts not modified for draft_lifetime_days
    bin/console tmonier:admin-order:purge-drafts --days=3 --dry-run
    ```

## Configuration

```yaml
# config/packages/tmonier_sylius_admin_order_creation.yaml
tmonier_sylius_admin_order_creation:
    allow_price_override: true             # custom unit prices
    allow_manual_discount: true            # order-level manual discount with a reason
    adjustments_role: ROLE_ADMINISTRATION_ACCESS   # role needed for both
    send_confirmation_email_default: true  # default state of the checkbox of the last step
    draft_lifetime_days: 7                 # default of tmonier:admin-order:purge-drafts
```

Sylius CE gives every administrator `ROLE_ADMINISTRATION_ACCESS`. To restrict custom prices and
discounts to some administrators, set `adjustments_role` to a role of your own
(`ROLE_ORDER_DISCOUNTS`...) and add it to the `roles` of those admin users. The check is a
Symfony voter (`TMONIER_ADMIN_ORDER_PRICE_OVERRIDE`, `TMONIER_ADMIN_ORDER_MANUAL_DISCOUNT`) that
you can replace or complete with your own voter.

The `AdminOrderCreation` resource can be extended like any Sylius resource
(`tmonier_sylius_admin_order_creation.resources.admin_order_creation.classes.model`).

## How it works

- **Drafts are regular carts.** The draft is a Sylius order in the `cart` state, created like
  the shop does (channel, base currency, locale, customer, default address) and marked by a
  row of the plugin table `tmonier_admin_order_creation`. It keeps the core "created by guest"
  flag, so the shop never resumes it as the customer's cart (the customer cart lookup only
  takes carts created by the logged-in customer).
- **Every change runs the core order processor** (`sylius.order_processing.order_processor`),
  exactly like a cart change in the shop.
- **Custom prices** set the item unit price and flag the item as *immutable*, the core flag
  that tells the price recalculator to keep it. The catalog price stays visible (struck
  through). Clearing the field restores the catalog price.
- **Manual discount**: the requested amount is stored as a neutral order adjustment (not
  counted in the totals, fixed label); the internal reason is stored in the plugin table only,
  never on the order (the shop API exposes adjustment labels). A plugin order processor running after the promotions
  and before the taxes spreads it over the units as regular *order promotion* adjustments
  labelled "Discount" (origin code `tmonier_admin_order_manual_discount`), capped to the items
  total. The minimum price of variants is not enforced for this administrator decision.
- **Changing a draft after the methods were chosen** (items, prices, coupon, discount) sends it
  back to the "addressed" checkout state: shipping and payment are confirmed again. Empty
  drafts cannot be placed.
- **Placement** runs in a transaction holding a lock on the order row: the state is read again
  (a double click or a concurrent deletion never places or deletes twice), the draft is
  recalculated with the shop's integrity check (`sylius.checker.order.promotions_integrity` and
  the total) and, if a promotion no longer applies or the total changed (expired promotion,
  coupon used up by other orders, new price), the admin is sent back to the summary with a
  warning. A coupon whose promotion no longer applies is taken off the order. The summary page
  also recalculates the draft when it is displayed.
- **Products**: only enabled variants of enabled products sold in the channel with a price
  there can be added (search and server-side check).
- **Disabled features**: when `allow_price_override` or `allow_manual_discount` is turned off,
  the custom prices or discount of the existing drafts are removed the next time the draft is
  opened. An administrator without `adjustments_role` cannot set them but can remove the ones
  given by an authorised administrator.
- **Drafts and expired carts**: the core `sylius:remove-expired-carts` remover is decorated to
  leave the drafts alone; `tmonier:admin-order:purge-drafts` handles their lifetime. Deletion
  and purge lock the order row and only delete drafts still in the cart state.
- **Checkout**: the plugin applies the `sylius_order_checkout` transitions (`address`,
  `select_shipping`, `select_payment`, `complete`) through the state machine; the core
  workflow listeners do the rest (recalculation, skipped steps, number, token, inventory hold,
  promotion usage, payment and shipment creation, customer address book). Before completion
  the order is validated with the groups of the shop's last step (`sylius`,
  `sylius_checkout_complete`): stock, product, shipping and payment method eligibility.
- **E-mails**: the order confirmation is sent with the core `OrderEmailManager` (same
  template as the shop) when requested. The payment link e-mail
  (`tmonier_admin_order_payment_link`, template `@TmonierSyliusAdminOrderCreationPlugin/email/payment_link.html.twig`)
  links to the shop order page of the order's channel. A failing mailer never prevents the
  order from being placed: a warning is shown and the error is logged.

## Customisation

| Hook | Content |
|---|---|
| `sylius_admin.order.index.content.header.title_block.actions` → `tmonier_admin_order_create` | *Create order* button |
| `sylius_admin.customer.show.content.header.title_block.actions` → `tmonier_admin_order_create` | *Create order* in the customer actions |
| `sylius_admin.order.show.content.header.title_block.actions.list` → `tmonier_admin_order_reorder` | *Reorder* action |
| `sylius_admin.order.show.content.sections.general` → `tmonier_admin_order_created_by` | "Created by", reorder origin, manual discount |
| `tmonier_admin_order.admin.page` | Layout of the plugin pages (start, drafts, steps) |

Templates can be overridden in `templates/bundles/TmonierSyliusAdminOrderCreationPlugin/`.

Listen to the creation event:

```php
#[AsEventListener(event: AdminOrderCreatedEvent::NAME)]
public function onAdminOrderCreated(AdminOrderCreatedEvent $event): void
{
    $order = $event->getOrder();          // placed, flushed, e-mails sent
    $admin = $event->getAdminUser();      // may be null
    $draft = $event->getCreation();       // source order, e-mail flags...
}
```

The building blocks are services: `DraftOrderFactoryInterface`, `DraftOrderModifierInterface`,
`DraftOrderCheckoutInterface`, `ReordererInterface` (for imports or another UI).

## Limitations

- Admin orders keep the core "created by guest" flag (also for customers with a shop account):
  the orders grid shows the guest icon. The order is still in the customer's account history.
- Adding the same variant twice merges the lines (core behaviour), including into a line with
  a custom price.
- The province field of addresses appears after a first submission for countries with
  provinces (no JavaScript reload).
- Each step is a full page reload (no live component): totals are refreshed after every change.
- Coupons, custom prices and manual discounts are not copied by *Reorder*.
- Sylius Plus RBAC is not integrated: the plugin pages are open to every administrator,
  only custom prices and discounts depend on `adjustments_role`.

## Development

```bash
cp compose.override.dist.yml compose.override.yml   # publishes MySQL on 3306 (edit the ports if needed)
docker compose up -d mysql mailhog
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)

# The MySQL container has no root password: point both environments at it.
echo 'DATABASE_URL=mysql://root@127.0.0.1:3306/tmonier_sylius_admin_order_creation_%kernel.environment%' > tests/TestApplication/.env.local
cp tests/TestApplication/.env.local tests/TestApplication/.env.test.local

for env in dev test; do
    APP_ENV=$env vendor/bin/console doctrine:database:create --if-not-exists
    APP_ENV=$env vendor/bin/console doctrine:migrations:migrate -n
    APP_ENV=$env vendor/bin/console sylius:fixtures:load -n
done

vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, on the test database
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

PHP needs more than 128 MB to compile the test container: prefix the console and PHPStan
commands with `php -d memory_limit=2G` if your `memory_limit` is lower. The tests create the
country, zone, shipping and payment methods and tax rate they need inside a transaction rolled
back after each test, so they do not depend on the random Sylius fixtures.

## License

MIT. See [LICENSE](LICENSE).
