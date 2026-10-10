# catalog:sync

An artisan command baked into the image that applies a YAML description of the
store's content:

| Section | Matched by | Applied through |
|---|---|---|
| `inventory_sources` | `code` | `InventorySourceRepository` |
| `categories` (nested via `children`) | `slug` | `CategoryRepository` |
| `config` | config code | `CoreConfigRepository` (same as Admin → Configuration) |
| `pages` | `url_key` | `PageRepository` |
| `products` | `sku` | Data Transfer product importer (validation, images, linking, indexing) |

Every section is optional and running the command again updates records in
place. Nothing is deleted: removing an entry from the file leaves the record in
the store.

See [`catalog.example.yaml`](catalog.example.yaml) for the format.

## Running it

```bash
php artisan catalog:sync /path/to/catalog.yaml --dry-run   # apply in a rolled-back transaction, validate products only
php artisan catalog:sync /path/to/catalog.yaml
```

With the Helm chart, set `catalogSync.enabled: true` and put the catalog under
`catalogSync.catalog`. It runs as a post-install/post-upgrade Job after the
migration Job. Secrets go in a Kubernetes Secret exposed through
`catalogSync.envFrom` and are referenced in the catalog as `${VAR}`:

```yaml
catalogSync:
  enabled: true
  envFrom:
    - secretRef:
        name: bagisto-stripe
  catalog:
    config:
      - values:
          sales:
            payment_methods:
              stripe:
                active: true
                api_test_key: ${STRIPE_TEST_SECRET_KEY}
```

## Notes

- Translatable fields take a string (same in every locale) or a map keyed by
  locale code; missing locales fall back to `default_locale`. A product with
  translated fields becomes one importer row per locale.
- Product `categories` are category **names**, as the importer resolves them;
  a name that matches no category is dropped silently, so define categories in
  the same file.
- Product list/map values are encoded the importer's way: `categories` joined
  with `/`, other lists with `,`, `inventories: {source: qty}` as
  `source=qty`, and `configurable_variants` as a list of maps.
- Config fields that depend on a sibling field (e.g. payment method fields on
  `active`) are only saved when that sibling is in the same entry.
