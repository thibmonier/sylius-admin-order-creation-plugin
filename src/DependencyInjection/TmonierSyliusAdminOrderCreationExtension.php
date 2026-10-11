<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusAdminOrderCreationPlugin\Mailer\Emails;

final class TmonierSyliusAdminOrderCreationExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{driver: string, resources: array<string, mixed>, allow_price_override: bool, allow_manual_discount: bool, adjustments_role: string, send_confirmation_email_default: bool, draft_lifetime_days: int} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_admin_order', $config['driver'], $config['resources'], $container);

        $container->setParameter('tmonier_admin_order.allow_price_override', $config['allow_price_override']);
        $container->setParameter('tmonier_admin_order.allow_manual_discount', $config['allow_manual_discount']);
        $container->setParameter('tmonier_admin_order.adjustments_role', $config['adjustments_role']);
        $container->setParameter('tmonier_admin_order.send_confirmation_email_default', $config['send_confirmation_email_default']);
        $container->setParameter('tmonier_admin_order.draft_lifetime_days', $config['draft_lifetime_days']);

        /** @var array<string, string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');
        $container->setParameter('tmonier_admin_order.shop_enabled', isset($bundles['SyliusShopBundle']));

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_admin_order.model.%s.class', $name), $resource['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusAdminOrderCreationPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusAdminOrderCreationPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $container->prependExtensionConfig('sylius_mailer', [
            'emails' => [
                Emails::PAYMENT_LINK => [
                    'subject' => 'tmonier_admin_order.email.payment_link.subject',
                    'template' => '@TmonierSyliusAdminOrderCreationPlugin/email/payment_link.html.twig',
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusAdminOrderCreationPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusAdminOrderCreationPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
