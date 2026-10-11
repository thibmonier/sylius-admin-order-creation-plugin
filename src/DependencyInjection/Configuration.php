<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreation;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepository;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_admin_order_creation');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('allow_price_override')
                    ->info('Let administrators replace the unit price of an item of a draft order.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('allow_manual_discount')
                    ->info('Let administrators give an order-level discount, with a reason, to a draft order.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('adjustments_role')
                    ->info('Security attribute (role) required to override prices and give manual discounts.')
                    ->defaultValue('ROLE_ADMINISTRATION_ACCESS')
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('send_confirmation_email_default')
                    ->info('Default state of the "send the order confirmation e-mail" checkbox of the last step.')
                    ->defaultTrue()
                ->end()
                ->integerNode('draft_lifetime_days')
                    ->info('Drafts not modified for this number of days are deleted by tmonier:admin-order:purge-drafts.')
                    ->defaultValue(7)
                    ->min(1)
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('admin_order_creation')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(AdminOrderCreation::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(AdminOrderCreationInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                        ->scalarNode('repository')->defaultValue(AdminOrderCreationRepository::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
