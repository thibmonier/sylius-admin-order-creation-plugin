# CLAUDE.md

Sylius 2 plugin letting administrators create orders on behalf of customers (phone and
back-office orders): drafts, items, custom prices, manual discount, coupon, addresses,
shipping and payment, placement through the shop checkout, reorder, payment link e-mail.
Functional spec and research: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:admin-order:purge-drafts --dry-run
symfony server:start -d --port=8087 --no-tls   # dev server, admin sylius / sylius
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3321, MailHog on
8040/1040), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_admin_order_creation_dev` / `_test`.

## Architecture

- `src/Entity/AdminOrderCreation` — plugin row per admin-built order (draft until
  `completedAt`). XML mapping in `config/doctrine` (mapped superclass). Never touch core entities.
- `src/Draft/DraftOrderFactory` — creates the cart like `ShopBasedCartContext`, keeps
  `createdByGuest = true` so the shop never resumes it as the customer's cart.
- `src/Draft/DraftOrderModifier` — items, custom prices (`immutable` items), manual discount
  request, coupon; every method runs the core order processor, none flushes.
- `src/Discount/ManualDiscountProcessor` — order processor at priority 15 (after promotions,
  before taxes): distributes the neutral request adjustment into `order_promotion` unit
  adjustments with origin code `tmonier_admin_order_manual_discount`.
- `src/Checkout/DraftOrderCheckout` — `sylius_order_checkout` transitions, validation groups
  `sylius` + `sylius_checkout_complete` (+ not empty); completion in a transaction with
  `OrderRowLock` and the shop's promotions/total integrity check; e-mails; event.
- `src/Remover` — `DraftRemover` (locked deletion) and the decorated core `ExpiredCartsRemover`
  that skips drafts. The manual discount reason lives in the plugin table only.
- `src/Reorder`, `src/Purger` + `src/Command/PurgeDraftsCommand`, `src/Mailer` (payment link).
- `src/Controller/Admin` — `DraftOrderController` (index, start, delete, reorder) and
  `DraftOrderStepsController` (items, address, shipping_payment, summary). Routes use the
  order id. Forms in `src/Form/Type`, autocompletes on `sylius_admin_entity_autocomplete`.
- `src/Security/OrderAdjustmentVoter` — config flags + `adjustments_role`.
- Templates: generic page layout hook `tmonier_admin_order.admin.page`; the page passes its
  variables in the hook context.

## Rules

- Go through the core processor and the state machine; never set states or totals by hand.
- Hook context values are dropped when null: use `hookable_metadata.context.x|default(null)`.
- Plugin forms need `{% form_theme form '@SyliusAdmin/shared/form_theme.html.twig' %}`.
- Model class parameters `%tmonier_admin_order.model.*.class%` are set in `prepend()`.
- Migrations use the Schema API (MySQL, MariaDB, PostgreSQL) and contain plugin tables only.
- Every translation key must exist in en, fr, de, es, it, nl, pl (`TranslationsTest` also checks
  that every key used in templates and PHP exists). Clear the cache after adding a file.
- Functional tests run in a transaction rolled back after each test; the entity manager is
  reset after every client request: reload entities (`reloadOrder()`) before asserting.
  `setUpWorld()` creates the country (IS), zone, shipping / payment methods and 10% tax used by
  every test and ends the fixture promotions: never rely on fixture methods, zones, taxes,
  promotions or order states.
