<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckout;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckoutInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Command\PurgeDraftsCommand;
use Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin\DraftOrderController;
use Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin\DraftOrderStepsController;
use Tmonier\SyliusAdminOrderCreationPlugin\Discount\ManualDiscountProcessor;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactory;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderFactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifier;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\CustomerAutocompleteType;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\ProductVariantAutocompleteType;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManager;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\PaymentLinkEmailManagerInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusAdminOrderCreationPlugin\Purger\DraftPurger;
use Tmonier\SyliusAdminOrderCreationPlugin\Purger\DraftPurgerInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Reorder\Reorderer;
use Tmonier\SyliusAdminOrderCreationPlugin\Reorder\ReordererInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Security\OrderAdjustmentVoter;
use Tmonier\SyliusAdminOrderCreationPlugin\Twig\AdminOrderCreationExtension;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->alias(AdminOrderCreationRepositoryInterface::class, 'tmonier_admin_order.repository.admin_order_creation');

    // Draft building
    $services
        ->set('tmonier_admin_order.draft.factory', DraftOrderFactory::class)
        ->args([
            service('sylius.factory.order'),
            service('tmonier_admin_order.factory.admin_order_creation'),
            service('sylius.manager.order'),
        ])
    ;
    $services->alias(DraftOrderFactoryInterface::class, 'tmonier_admin_order.draft.factory');

    $services
        ->set('tmonier_admin_order.draft.modifier', DraftOrderModifier::class)
        ->args([
            service('sylius.factory.order_item'),
            service('sylius.modifier.order'),
            service('sylius.modifier.order_item_quantity'),
            service('sylius.order_processing.order_processor'),
            service('sylius.factory.adjustment'),
            service('sylius.repository.promotion_coupon'),
            service('sylius.checker.promotion_coupon_eligibility'),
        ])
    ;
    $services->alias(DraftOrderModifierInterface::class, 'tmonier_admin_order.draft.modifier');

    // After the promotions (20), before the taxes (10).
    $services
        ->set('tmonier_admin_order.order_processing.manual_discount', ManualDiscountProcessor::class)
        ->args([
            service('sylius.factory.adjustment'),
            service('sylius.distributor.proportional_integer'),
            service('translator'),
        ])
        ->tag('sylius.order_processor', ['priority' => 15])
    ;

    $services
        ->set('tmonier_admin_order.checkout', DraftOrderCheckout::class)
        ->args([
            service('sylius_abstraction.state_machine'),
            service('validator'),
            service('sylius.manager.order'),
            service('sylius.mailer.order_email_manager'),
            service('tmonier_admin_order.mailer.payment_link'),
            service('event_dispatcher'),
            service('clock'),
            service('logger'),
        ])
        ->tag('monolog.logger', ['channel' => 'tmonier_admin_order'])
    ;
    $services->alias(DraftOrderCheckoutInterface::class, 'tmonier_admin_order.checkout');

    $services
        ->set('tmonier_admin_order.mailer.payment_link', PaymentLinkEmailManager::class)
        ->args([
            service('sylius.email_sender'),
            param('tmonier_admin_order.shop_enabled'),
        ])
    ;
    $services->alias(PaymentLinkEmailManagerInterface::class, 'tmonier_admin_order.mailer.payment_link');

    $services
        ->set('tmonier_admin_order.reorderer', Reorderer::class)
        ->args([
            service('tmonier_admin_order.draft.factory'),
            service('tmonier_admin_order.draft.modifier'),
        ])
    ;
    $services->alias(ReordererInterface::class, 'tmonier_admin_order.reorderer');

    $services
        ->set('tmonier_admin_order.purger', DraftPurger::class)
        ->args([
            service('tmonier_admin_order.repository.admin_order_creation'),
            service('sylius.manager.order'),
        ])
    ;
    $services->alias(DraftPurgerInterface::class, 'tmonier_admin_order.purger');

    $services
        ->set('tmonier_admin_order.command.purge_drafts', PurgeDraftsCommand::class)
        ->args([
            service('tmonier_admin_order.purger'),
            service('clock'),
            param('tmonier_admin_order.draft_lifetime_days'),
        ])
        ->tag('console.command')
    ;

    // Security
    $services
        ->set('tmonier_admin_order.security.adjustment_voter', OrderAdjustmentVoter::class)
        ->args([
            service('security.access.decision_manager'),
            param('tmonier_admin_order.allow_price_override'),
            param('tmonier_admin_order.allow_manual_discount'),
            param('tmonier_admin_order.adjustments_role'),
        ])
        ->tag('security.voter')
    ;

    // Back office
    $services
        ->set('tmonier_admin_order.controller.admin.draft', DraftOrderController::class)
        ->args([
            service('tmonier_admin_order.repository.admin_order_creation'),
            service('sylius.repository.customer'),
            service('sylius.factory.customer'),
            service('sylius.repository.order'),
            service('tmonier_admin_order.draft.factory'),
            service('tmonier_admin_order.reorderer'),
            service('sylius.manager.order'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    $services
        ->set('tmonier_admin_order.controller.admin.draft_steps', DraftOrderStepsController::class)
        ->args([
            service('tmonier_admin_order.repository.admin_order_creation'),
            service('tmonier_admin_order.draft.modifier'),
            service('tmonier_admin_order.checkout'),
            service('sylius.resolver.shipping_methods'),
            service('sylius.resolver.payment_methods'),
            service('sylius.registry.shipping_calculator'),
            service('sylius.formatter.money'),
            service('sylius.factory.address'),
            service('sylius.manager.order'),
            param('tmonier_admin_order.send_confirmation_email_default'),
        ])
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public()
    ;

    $services
        ->set('tmonier_admin_order.form.type.customer_autocomplete', CustomerAutocompleteType::class)
        ->args([param('sylius.model.customer.class')])
        ->tag('form.type')
        ->tag('ux.entity_autocomplete_field')
    ;

    $services
        ->set('tmonier_admin_order.form.type.product_variant_autocomplete', ProductVariantAutocompleteType::class)
        ->args([param('sylius.model.product_variant.class')])
        ->tag('form.type')
        ->tag('ux.entity_autocomplete_field')
    ;

    $services
        ->set('tmonier_admin_order.twig.extension', AdminOrderCreationExtension::class)
        ->args([
            service('tmonier_admin_order.repository.admin_order_creation'),
            service('tmonier_admin_order.draft.modifier'),
            service('tmonier_admin_order.reorderer'),
        ])
        ->tag('twig.extension')
    ;

    $services
        ->set('tmonier_admin_order.menu.admin', AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main'])
    ;
};
