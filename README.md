# IN10 - Drupal Laravel Cache

Drupal module that automatically invalidates Laravel cache entries whenever a node is created, updated or deleted.

Cache invalidation is **tag-based**: each entity flush sends granular tags so only the affected caches are cleared while other records of the same type stay cached.

## Installation

Add the repository to your `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:IN10/drupal_laravel_cache"
    }
]
```

Then require the package:

```bash
composer require in10/drupal_laravel_cache
```

Enable the module:

```bash
drush en drupal_laravel_cache
```

## Configuration

Add the following to your `settings.php` (or a file it includes, e.g. `settings.local.php`):

```php
$settings['laravel'] = [
    'base_url'       => 'https://my-laravel-app.com',
    'cache_endpoint' => '/api/cache/invalidate',
];
```

| Key              | Required | Description                                              |
|------------------|----------|----------------------------------------------------------|
| `base_url`       | Yes      | The base URL of your Laravel application.                |
| `cache_endpoint` | Yes      | The path on the Laravel side that handles invalidation.  |
| `bundle_map`     | Yes      | Maps Drupal bundle names to API gateway type names.      |

### Bundle map

The `bundle_map` is required because Drupal bundle machine names (e.g. `ct_event`, `ct_news`) don't match the API gateway type names (e.g. `events`, `news`). The map translates between the two so that cache tags sent by Drupal match what the API gateway actually cached.

Bundles **not listed** in the map will not trigger any cache invalidation.

```php
$settings['laravel'] = [
    'base_url'       => 'https://my-laravel-app.com',
    'cache_endpoint' => '/api/cache/invalidate',
    'bundle_map'     => [
        'ct_news'            => 'news',
        'ct_page'            => 'pages',
        'ct_event'           => 'events',
        'ct_storycard'       => 'storycards',
        'ct_longread'        => 'longreads',
        'ct_vacancy'         => 'vacancies',
        'ct_global_settings' => 'global_settings',
        'ct_space'           => 'spaces',
    ],
];
```

## How it works

The module hooks into three Drupal entity lifecycle events for nodes:

- `hook_entity_insert` — node created
- `hook_entity_update` — node updated
- `hook_entity_delete` — node deleted

On each event it sends a `POST` request to the configured Laravel endpoint with a JSON body containing an array of cache tags to invalidate:

```json
{
    "tags": ["articles:index", "articles:path:/my-article"]
}
```

### Tags sent

| Tag format             | Purpose                                                                 |
|------------------------|-------------------------------------------------------------------------|
| `{type}:index`         | Invalidates list/index caches so changes appear in overviews.           |
| `{type}:path:{alias}`  | Invalidates the single-record cache for each language-specific alias.   |

### No-cache session bypass

If `$_SESSION['no-cache']` is set to a bundle name, invalidation is skipped for that bundle. This can be used during migrations or imports to prevent excessive cache flushes.

## Clear all caches

To flush the entire Laravel cache, visit `/admin/flush-laravel-cache` or call `DrupalLaravelCacheController::clearAll()` programmatically.