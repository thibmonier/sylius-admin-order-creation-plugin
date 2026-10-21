<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusAdminOrderCreationPlugin\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationsTest extends TestCase
{
    private const LOCALES = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pl'];

    private const DOMAINS = ['messages', 'flashes', 'validators'];

    /** Strings of the tmonier_admin_order namespace that are not translation keys. */
    private const NON_TRANSLATION_IDS = [
        'tmonier_admin_order.order_created',
        'tmonier_admin_order.admin.page',
        'tmonier_admin_order.shop_enabled',
        'tmonier_admin_order.allow_price_override',
        'tmonier_admin_order.allow_manual_discount',
        'tmonier_admin_order.adjustments_role',
        'tmonier_admin_order.send_confirmation_email_default',
        'tmonier_admin_order.draft_lifetime_days',
        'tmonier_admin_order.restrict_drafts_to_owner',
        'tmonier_admin_order.address_source',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                yield sprintf('%s.%s', $domain, $locale) => [$domain, $locale];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testEveryLocaleDefinesTheSameKeys(string $domain, string $locale): void
    {
        $reference = $this->flatten($this->parse($domain, 'en'));
        $catalogue = $this->flatten($this->parse($domain, $locale));

        self::assertSame([], array_values(array_diff(array_keys($reference), array_keys($catalogue))), 'Missing keys');
        self::assertSame([], array_values(array_diff(array_keys($catalogue), array_keys($reference))), 'Unknown keys');
        foreach ($catalogue as $key => $value) {
            self::assertNotSame('', trim($value), sprintf('Empty translation "%s"', $key));
            preg_match_all('/%\w+%/', $reference[$key], $expected);
            preg_match_all('/%\w+%/', $value, $actual);
            self::assertEqualsCanonicalizing($expected[0], $actual[0], sprintf('Placeholders of "%s"', $key));
        }
    }

    /** Every translation key used by the templates and the PHP code exists in English. */
    public function testEveryKeyUsedByThePluginIsTranslated(): void
    {
        $keys = [];
        foreach (self::DOMAINS as $domain) {
            $keys += $this->flatten($this->parse($domain, 'en'));
        }

        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates'));
        $sources = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $sources[] = (string) file_get_contents($file->getPathname());
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $sources[] = (string) file_get_contents($file->getPathname());
            }
        }

        preg_match_all("/'(tmonier_admin_order\\.[a-z_.]+[a-z_])'/", implode("\n", $sources), $matches);
        $missing = [];
        foreach (array_unique($matches[1]) as $key) {
            if (str_ends_with($key, '.step') || str_starts_with($key, 'tmonier_admin_order.draft.') || str_starts_with($key, 'tmonier_admin_order.order_processing.') || isset($keys[$key])) {
                continue;
            }
            if (in_array($key, self::NON_TRANSLATION_IDS, true)) {
                continue;
            }
            $missing[] = $key;
        }

        self::assertSame([], $missing);
    }

    /** @return array<mixed> */
    private function parse(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/../../translations/%s.%s.yaml', __DIR__, $domain, $locale));
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix . $key . '.');

                continue;
            }

            $flat[$prefix . $key] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }
}
