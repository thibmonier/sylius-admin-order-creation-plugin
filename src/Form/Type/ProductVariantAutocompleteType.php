<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Type;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Enabled variants of enabled products, searched by product name (any locale) or variant code,
 * restricted to the channel passed in "extra_options" (signed by Symfony UX in the URL).
 */
#[AsEntityAutocompleteField(
    alias: 'tmonier_admin_order_product_variant',
    route: 'sylius_admin_entity_autocomplete',
)]
final class ProductVariantAutocompleteType extends AbstractType
{
    public function __construct(
        private readonly string $productVariantClass,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => $this->productVariantClass,
            'choice_label' => self::label(...),
            'security' => 'ROLE_ADMINISTRATION_ACCESS',
            'max_results' => 20,
            'filter_query' => static function (Options $options): callable {
                $extraOptions = $options['extra_options'];
                $channelCode = is_array($extraOptions) ? ($extraOptions['channel'] ?? null) : null;

                return static function (QueryBuilder $queryBuilder, string $query) use ($channelCode): void {
                    $queryBuilder
                        ->distinct()
                        ->innerJoin('entity.product', 'product')
                        ->leftJoin('product.translations', 'productTranslation')
                        ->andWhere('entity.enabled = true')
                        ->andWhere('product.enabled = true')
                        ->andWhere('LOWER(entity.code) LIKE :query OR LOWER(productTranslation.name) LIKE :query')
                        ->setParameter('query', '%' . mb_strtolower($query) . '%')
                    ;

                    if (is_string($channelCode)) {
                        $queryBuilder
                            ->innerJoin('product.channels', 'channel')
                            ->innerJoin('entity.channelPricings', 'channelPricing', 'WITH', 'channelPricing.channelCode = :channelCode')
                            ->andWhere('channel.code = :channelCode')
                            ->andWhere('channelPricing.price IS NOT NULL')
                            ->setParameter('channelCode', $channelCode)
                        ;
                    }
                };
            },
        ]);
    }

    public static function label(ProductVariantInterface $variant): string
    {
        $productName = (string) $variant->getProduct()?->getName();
        $variantName = (string) $variant->getName();

        if ('' === $variantName || $variantName === $productName) {
            return sprintf('%s (%s)', $productName, (string) $variant->getCode());
        }

        return sprintf('%s - %s (%s)', $productName, $variantName, (string) $variant->getCode());
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_admin_order_product_variant_autocomplete';
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
