<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\CMS\Repositories\PageRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\CoreConfigRepository;
use Webkul\DataTransfer\Helpers\Import as ImportHelper;
use Webkul\DataTransfer\Repositories\ImportRepository;
use Webkul\Inventory\Repositories\InventorySourceRepository;

/**
 * Applies a declarative catalog file (inventory sources, categories, configuration,
 * CMS pages and products) to the store. Every section is keyed by a natural
 * identifier (code, slug, url_key, sku), so running it again updates in place.
 *
 * Products go through Bagisto's own Data Transfer importer, so they get the same
 * validation, image handling and search indexing as an admin import.
 */
class CatalogSync extends Command
{
    protected $signature = 'catalog:sync
        {file : Path to the catalog YAML file}
        {--dry-run : Apply everything inside a transaction that is rolled back, and only validate products}';

    protected $description = 'Create or update inventory sources, categories, configuration, CMS pages and products from a YAML file';

    /**
     * Product columns whose list values are joined with "/" instead of ",".
     */
    protected const SLASH_JOINED_COLUMNS = ['categories'];

    protected const CATEGORY_TRANSLATED_FIELDS = ['name', 'description', 'slug', 'meta_title', 'meta_description', 'meta_keywords'];

    protected const PAGE_TRANSLATED_FIELDS = ['page_title', 'html_content', 'url_key', 'meta_title', 'meta_description', 'meta_keywords'];

    protected string $defaultLocale;

    /**
     * @var array<string, int> locale code => locale id
     */
    protected array $locales = [];

    public function __construct(
        protected CategoryRepository $categoryRepository,
        protected ChannelRepository $channelRepository,
        protected CoreConfigRepository $coreConfigRepository,
        protected ImportRepository $importRepository,
        protected InventorySourceRepository $inventorySourceRepository,
        protected PageRepository $pageRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }

        $catalog = $this->interpolateEnv(Yaml::parseFile($file) ?? []);

        $this->locales = core()->getAllLocales()->pluck('id', 'code')->all();

        $this->defaultLocale = $catalog['default_locale'] ?? core()->getDefaultLocaleCodeFromDefaultChannel();

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->syncInventorySources($catalog['inventory_sources'] ?? []);

            $this->syncCategories($catalog['categories'] ?? [], $this->rootCategoryId($catalog));

            $this->syncConfig($catalog['config'] ?? []);

            $this->syncPages($catalog['pages'] ?? []);

            $ok = $this->syncProducts($catalog['products'] ?? [], $dryRun);
        } finally {
            if ($dryRun) {
                DB::rollBack();

                $this->warn('Dry run: all changes were rolled back.');
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Replace "${VAR}" in string values with the environment variable, so secrets
     * (e.g. Stripe keys) can come from a Kubernetes Secret instead of the file.
     */
    protected function interpolateEnv(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->interpolateEnv($item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', function ($matches) {
            $env = getenv($matches[1]);

            if ($env === false) {
                throw new \RuntimeException("Environment variable {$matches[1]} referenced in the catalog is not set");
            }

            return $env;
        }, $value);
    }

    protected function syncInventorySources(array $sources): void
    {
        foreach ($sources as $source) {
            $data = array_merge(['status' => 1, 'priority' => 0], $source);

            $existing = $this->inventorySourceRepository->findOneByField('code', $source['code']);

            if ($existing) {
                $this->inventorySourceRepository->update($data, $existing->id);
            } else {
                $this->inventorySourceRepository->create($data);
            }

            $this->line(sprintf('inventory source %s: %s', $source['code'], $existing ? 'updated' : 'created'));
        }
    }

    protected function rootCategoryId(array $catalog): int
    {
        $channelCode = $catalog['channel'] ?? core()->getDefaultChannelCode();

        return $this->channelRepository->findOneByField('code', $channelCode)->root_category_id;
    }

    protected function syncCategories(array $categories, int $parentId): void
    {
        foreach ($categories as $position => $item) {
            $data = array_merge(
                [
                    'parent_id' => $parentId,
                    'position' => $item['position'] ?? $position + 1,
                    'status' => (int) ($item['status'] ?? 1),
                    'display_mode' => $item['display_mode'] ?? 'products_and_description',
                ],
                $this->translations(self::CATEGORY_TRANSLATED_FIELDS, $item, withLocaleId: true)
            );

            $existing = $this->categoryRepository->findBySlug($item['slug']);

            $category = $existing
                ? $this->categoryRepository->update($data, $existing->id)
                : $this->categoryRepository->create($data);

            $this->line(sprintf('category %s: %s', $item['slug'], $existing ? 'updated' : 'created'));

            $this->syncCategories($item['children'] ?? [], $category->id);
        }
    }

    /**
     * Each entry is the same nested structure the admin configuration form posts,
     * e.g. {sales: {payment_methods: {stripe: {active: true}}}}. A field that
     * "depends" on a sibling (like most payment method fields on "active") is only
     * saved when that sibling is part of the same entry.
     */
    protected function syncConfig(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->coreConfigRepository->create(array_merge($entry['values'] ?? [], [
                'channel' => $entry['channel'] ?? core()->getDefaultChannelCode(),
                'locale' => $entry['locale'] ?? $this->defaultLocale,
            ]));

            $this->line(sprintf(
                'config [%s/%s]: %s',
                $entry['channel'] ?? core()->getDefaultChannelCode(),
                $entry['locale'] ?? $this->defaultLocale,
                implode(', ', array_keys($entry['values'] ?? []))
            ));
        }
    }

    protected function syncPages(array $pages): void
    {
        foreach ($pages as $item) {
            $channelCodes = $item['channels'] ?? [core()->getDefaultChannelCode()];

            $data = array_merge(
                [
                    'layout' => $item['layout'] ?? null,
                    'channels' => $this->channelRepository->findWhereIn('code', $channelCodes)->pluck('id')->all(),
                    'locale' => $this->defaultLocale,
                ],
                $this->translations(self::PAGE_TRANSLATED_FIELDS, $item)
            );

            $existing = $this->pageRepository->getModel()
                ->whereTranslation('url_key', $item['url_key'])
                ->first();

            if ($existing) {
                $this->pageRepository->update($data, $existing->id);
            } else {
                $this->pageRepository->create($data);
            }

            $this->line(sprintf('page %s: %s', $item['url_key'], $existing ? 'updated' : 'created'));
        }
    }

    /**
     * Expand translatable fields into the per-locale arrays Bagisto's translatable
     * models expect. A plain string applies to every locale; a map keyed by locale
     * code sets each one, falling back to the default locale's value.
     */
    protected function translations(array $fields, array $item, bool $withLocaleId = false): array
    {
        $data = [];

        foreach ($this->locales as $code => $id) {
            $data[$code] = $withLocaleId ? ['locale_id' => $id] : [];

            foreach ($fields as $field) {
                if (! array_key_exists($field, $item)) {
                    continue;
                }

                $value = $item[$field];

                $data[$code][$field] = is_array($value)
                    ? ($value[$code] ?? $value[$this->defaultLocale] ?? reset($value))
                    : $value;
            }
        }

        return $data;
    }

    protected function syncProducts(array $products, bool $dryRun): bool
    {
        if (empty($products['items'])) {
            return true;
        }

        $import = $this->importRepository->create([
            'type' => 'products',
            'action' => $products['action'] ?? ImportHelper::ACTION_APPEND,
            'process_in_queue' => false,
            'validation_strategy' => $products['validation_strategy'] ?? ImportHelper::VALIDATION_STRATEGY_STOP_ON_ERROR,
            'allowed_errors' => $products['allowed_errors'] ?? 0,
            'field_separator' => ',',
            'image_source' => $products['image_source'] ?? ImportHelper::IMAGE_SOURCE_URL,
            'images_directory_path' => $products['images_directory_path'] ?? null,
            'file_path' => '',
        ]);

        $filePath = "imports/{$import->id}/products.csv";

        Storage::disk('private')->put($filePath, $this->productsCsv($products['items']));

        $import = $this->importRepository->update(['file_path' => $filePath], $import->id);

        $helper = app(ImportHelper::class)->setImport($import);

        try {
            if (! $helper->validate()) {
                $this->error('Product import is not valid:');

                foreach ($helper->getImport()->errors ?? [] as $error) {
                    $this->line('  '.(is_array($error) ? json_encode($error) : $error));
                }

                $this->line('System errors are logged with details in storage/logs.');

                return false;
            }

            $this->line(sprintf('products: %d rows valid', $helper->getImport()->processed_rows_count));

            if ($dryRun) {
                return true;
            }

            $this->runImport($helper);
        } finally {
            if ($dryRun) {
                Storage::disk('private')->deleteDirectory("imports/{$import->id}");
            }
        }

        $summary = $helper->getImport()->summary ?? [];

        $this->info(sprintf(
            'products: %d created, %d updated, %d deleted',
            $summary['created'] ?? 0,
            $summary['updated'] ?? 0,
            $summary['deleted'] ?? 0
        ));

        return true;
    }

    /**
     * Drive the import through the same phases the admin screen does, in process
     * rather than on a queue: download images, import, link, index.
     */
    protected function runImport(ImportHelper $helper): void
    {
        if ($helper->hasImagePhase()) {
            do {
                $progress = $helper->downloadImages();

                $this->line(sprintf('images: %d/%d (%d failed)', $progress['processed'], $progress['total'], $progress['failed']));
            } while (! $progress['done']);
        }

        $helper->started();

        $this->eachBatch($helper, ImportHelper::STATE_PENDING, fn ($batch) => $helper->start($batch));

        $readyState = ImportHelper::STATE_PROCESSED;

        if ($helper->isLinkingRequired()) {
            $helper->linking();

            $this->eachBatch($helper, ImportHelper::STATE_PROCESSED, fn ($batch) => $helper->link($batch));

            $readyState = ImportHelper::STATE_LINKED;
        }

        if ($helper->isIndexingRequired()) {
            $helper->indexing();

            $this->eachBatch($helper, $readyState, fn ($batch) => $helper->index($batch));
        }

        $helper->completed();
    }

    protected function eachBatch(ImportHelper $helper, string $state, callable $callback): void
    {
        $batches = $helper->getImport()->batches()->where('state', $state)->get();

        foreach ($batches as $batch) {
            $callback($batch);
        }
    }

    /**
     * Build the Data Transfer CSV. Translatable values given as a locale map
     * produce one row per locale; lists and maps are encoded the way the
     * importer reads them (categories "A/B", images "a,b", inventories
     * "source=qty,...", configurable_variants "sku=..,color=..|...").
     */
    protected function productsCsv(array $items): string
    {
        $rows = [];

        foreach ($items as $item) {
            foreach ($this->productRows($item) as $row) {
                $rows[] = $row;
            }
        }

        $columns = ['sku', 'locale'];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $columns), escape: '');
        }

        rewind($handle);

        return stream_get_contents($handle);
    }

    protected function productRows(array $item): array
    {
        $localized = [];

        $scalar = [];

        foreach ($item as $column => $value) {
            if ($this->isLocaleMap($column, $value)) {
                $localized[$column] = $value;
            } else {
                $scalar[$column] = $this->encodeProductValue($column, $value);
            }
        }

        $rowLocales = $localized
            ? array_values(array_unique(array_merge([$this->defaultLocale], ...array_map('array_keys', array_values($localized)))))
            : [$item['locale'] ?? $this->defaultLocale];

        return array_map(function ($locale) use ($scalar, $localized) {
            $row = array_merge($scalar, ['locale' => $locale]);

            foreach ($localized as $column => $values) {
                $row[$column] = $values[$locale] ?? $values[$this->defaultLocale] ?? reset($values);
            }

            return $row;
        }, $rowLocales);
    }

    protected function isLocaleMap(string $column, mixed $value): bool
    {
        return is_array($value)
            && Arr::isAssoc($value)
            && $column !== 'inventories'
            && empty(array_diff(array_keys($value), array_keys($this->locales)));
    }

    protected function encodeProductValue(string $column, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_array($value)) {
            return (string) $value;
        }

        if (Arr::isAssoc($value)) {
            return $this->encodePairs($value, ',');
        }

        if (isset($value[0]) && is_array($value[0])) {
            return implode('|', array_map(fn ($pairs) => $this->encodePairs($pairs, ','), $value));
        }

        return implode(in_array($column, self::SLASH_JOINED_COLUMNS, true) ? '/' : ',', $value);
    }

    protected function encodePairs(array $pairs, string $separator): string
    {
        return implode($separator, array_map(
            fn ($key, $value) => $key.'='.(is_bool($value) ? (int) $value : $value),
            array_keys($pairs),
            $pairs
        ));
    }
}
