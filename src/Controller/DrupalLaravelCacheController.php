<?php

namespace Drupal\drupal_laravel_cache\Controller;

use Drupal\Core\Site\Settings;
use GuzzleHttp\Client;

class DrupalLaravelCacheController
{
    /**
     * @var string
     */
    protected $laravelUrl;

    /**
     * Map of Drupal bundle machine names → api-gateway cache tag names.
     * Configured under settings['laravel']['bundle_map'] in settings.php.
     *
     * Required because Drupal bundles (e.g. ct_event) don't match the
     * API gateway type names (e.g. events). Bundles not in the map
     * will not trigger cache invalidation.
     *
     * @var array<string,string>
     */
    protected $bundleMap;

    /**
     * Base URL of the API gateway (without the endpoint path).
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Shared secret sent as X-Gateway-Secret on every webhook call. Must match
     * GATEWAY_WEBHOOK_SECRET on the gateway. Null = send no header (gateway then
     * treats the endpoints as unprotected, i.e. legacy behaviour).
     *
     * @var string|null
     */
    protected $secret;

    /**
     * Base path of the route-index endpoints (e.g. "/admin/v1.0/route-index").
     * Null = route-index syncing is disabled (only cache invalidation runs).
     *
     * @var string|null
     */
    protected $routeIndexEndpoint;

    public function __construct()
    {
        $laravelSettings = Settings::get('laravel');
        $this->baseUrl = $laravelSettings['base_url'] ?? '';
        $this->laravelUrl = $this->baseUrl . ($laravelSettings['cache_endpoint'] ?? '');
        $this->bundleMap = isset($laravelSettings['bundle_map']) && is_array($laravelSettings['bundle_map'])
            ? $laravelSettings['bundle_map']
            : [];
        $this->secret = $laravelSettings['webhook_secret'] ?? null;
        $this->routeIndexEndpoint = $laravelSettings['route_index_endpoint'] ?? null;
    }

    /**
     * Invalidate the Laravel cache for a single entity.
     *
     * Sends per-record tags ("{type}:path:{alias}") for every language that has
     * an alias for this node, plus "{type}:index" so any cached lists refresh.
     * Other records of the same type stay cached.
     */
    public function invalidateCache($entity)
    {
        $bundle = $entity->bundle();

        if (isset($_SESSION['no-cache']) && $_SESSION['no-cache'] === $bundle) {
            \Drupal::logger('drupal_laravel_cache')->notice('No cache cleared for ' . $bundle);
            return;
        }

        if (!isset($this->bundleMap[$bundle])) {
            \Drupal::logger('drupal_laravel_cache')->notice(
                'No cache tag mapping for bundle "' . $bundle . '"; skipping invalidation.'
            );
            return;
        }

        $type = $this->bundleMap[$bundle];

        // Always flush the type's index — any list view that includes this record
        // needs to re-fetch so a renamed/edited title shows up there too.
        $tags = ["{$type}:index"];

        // Add a per-language path tag for each alias this node has.
        // The api-gateway tags single-record caches as "{type}:path:{alias}",
        // mirroring the field_path filter it uses to look the entity up.
        foreach ($this->resolveEntityPathAliases($entity) as $alias) {
            $tags[] = "{$type}:path:{$alias}";
        }

        $this->flushTag($tags);
    }

    /**
     * Collect the path aliases for an entity across every enabled language.
     *
     * Returns the unique set of alias strings (e.g. "/agenda/brogeal").
     * Skips languages where no alias exists — falling back to /node/{id}
     * would never match an api-gateway cache key, so there's nothing to flush.
     *
     * @return string[]
     */
    protected function resolveEntityPathAliases($entity): array
    {
        // Path aliases live on URI'd entities (nodes, taxonomy terms…). Be defensive.
        if (!method_exists($entity, 'id')) {
            return [];
        }

        try {
            $internalPath = '/' . $entity->getEntityTypeId() . '/' . $entity->id();
            $aliasManager = \Drupal::service('path_alias.manager');
            $languageManager = \Drupal::languageManager();
        } catch (\Throwable $e) {
            \Drupal::logger('drupal_laravel_cache')->error(
                'Could not resolve path aliases: ' . $e->getMessage()
            );
            return [];
        }

        $aliases = [];
        foreach ($languageManager->getLanguages() as $langcode => $language) {
            $alias = $aliasManager->getAliasByPath($internalPath, $langcode);
            // getAliasByPath() returns the original path when no alias is set.
            if ($alias !== $internalPath) {
                $aliases[$alias] = true;
            }
        }

        return array_keys($aliases);
    }

    /**
     * Send one or more tags to the api-gateway for invalidation.
     *
     * Accepts either a single tag string or an array of tag strings,
     * for backwards compatibility with older callers.
     *
     * @param string|string[] $tags
     */
    public function flushTag($tags)
    {
        if (!is_array($tags)) {
            $tags = [$tags];
        }

        $tags = array_values(array_filter($tags, function ($tag) {
            return is_string($tag) && trim($tag) !== '';
        }));

        if (empty($tags)) {
            \Drupal::logger('drupal_laravel_cache')->notice('flushTag called with no usable tags; skipping.');
            return;
        }

        try {
            $client = new Client();
            $client->request('POST', $this->laravelUrl, [
                // 'json' sets Content-Type: application/json AND json_encodes the body,
                // which is what Laravel's request->input() expects to parse.
                'json' => [
                    'tags' => $tags,
                ],
                'headers' => $this->webhookHeaders(),
            ]);
            \Drupal::logger('drupal_laravel_cache')->notice('Caches invalidated for tags ' . implode(', ', $tags));
        } catch (\Exception $exception) {
            \Drupal::logger('drupal_laravel_cache')->error($exception->getMessage());
            \Drupal::logger('drupal_laravel_cache')->notice('Something went wrong while invalidating Laravel cache. Please check Laravel logs for the cause.');
        }
    }

    /**
     * Call Laravel to clear all content from cache.
     */
    public function clearAll()
    {
        try {
            $client = new Client();
            // Empty body → api-gateway hits the fallback flush-all branch.
            $client->request('POST', $this->laravelUrl, [
                'json' => new \stdClass(),
                'headers' => $this->webhookHeaders(),
            ]);
            $message = 'Caches invalidated for all content';
            \Drupal::logger('drupal_laravel_cache')->notice($message);
        } catch (\Exception $exception) {
            \Drupal::logger('drupal_laravel_cache')->error($exception->getMessage());
            $message = 'Something went wrong while clearing the cache.';
            \Drupal::logger('drupal_laravel_cache')->notice('Something went wrong while invalidating Laravel cache. Please check Laravel logs for the cause.');
        }

        return [
            '#markup' => t($message),
        ];
    }

    /**
     * Keep the gateway's route index in sync with this node.
     *
     * Cache invalidation (above) only tells the gateway "the content at this path
     * changed, re-fetch it" — it says nothing about which paths EXIST. The route
     * index is that list: it lets the gateway return an instant 404 for unknown
     * paths (scans/junk) without ever calling Drupal. To stay accurate it needs
     * to be told when a page appears or disappears, which is what this does:
     *
     *   - published translation   → add its field_path   (it's now a valid route)
     *   - unpublished translation → remove its field_path
     *   - node deleted ($removed) → remove every field_path
     *
     * Uses field_path (the exact value the gateway filters/looks up on), per
     * translation, so the index key always matches the lookup key.
     *
     * @param object $entity
     * @param bool   $removed  true when the node is being deleted
     */
    public function syncRouteIndex($entity, bool $removed = false): void
    {
        // Only run when the route-index endpoint is configured in settings.
        if (empty($this->routeIndexEndpoint)) {
            return;
        }

        foreach ($this->fieldPathsByLanguage($entity) as $langcode => $path) {
            if ($removed) {
                $this->routeIndex('remove', $langcode, $path);
                continue;
            }

            $translation = $this->translationFor($entity, $langcode);
            $published = !method_exists($translation, 'isPublished') || $translation->isPublished();

            $this->routeIndex($published ? 'add' : 'remove', $langcode, $path);
        }
    }

    /**
     * POST a single add/remove to the gateway route-index endpoint.
     */
    protected function routeIndex(string $op, string $locale, string $path): void
    {
        $url = $this->baseUrl . $this->routeIndexEndpoint . '/' . $op;

        try {
            (new Client())->request('POST', $url, [
                'json' => [
                    'locale' => $locale,
                    'path' => $path,
                ],
                'headers' => $this->webhookHeaders(),
            ]);
            \Drupal::logger('drupal_laravel_cache')->notice("Route index {$op}: [{$locale}] {$path}");
        } catch (\Exception $exception) {
            \Drupal::logger('drupal_laravel_cache')->error(
                "Route index {$op} failed for [{$locale}] {$path}: " . $exception->getMessage()
            );
        }
    }

    /**
     * Collect the field_path for each translation of the node.
     *
     * @return array<string,string> langcode => field_path
     */
    protected function fieldPathsByLanguage($entity): array
    {
        if (!method_exists($entity, 'hasField') || !$entity->hasField('field_path')) {
            return [];
        }

        $langcodes = method_exists($entity, 'getTranslationLanguages')
            ? array_keys($entity->getTranslationLanguages())
            : [$entity->language()->getId()];

        $paths = [];
        foreach ($langcodes as $langcode) {
            try {
                $value = $this->translationFor($entity, $langcode)->get('field_path')->value ?? null;
            } catch (\Throwable $e) {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $paths[$langcode] = trim($value);
            }
        }

        return $paths;
    }

    /**
     * Get the entity's translation for a langcode, falling back to the entity.
     */
    protected function translationFor($entity, string $langcode)
    {
        try {
            if (method_exists($entity, 'hasTranslation') && $entity->hasTranslation($langcode)) {
                return $entity->getTranslation($langcode);
            }
        } catch (\Throwable $e) {
            // fall through to the untranslated entity
        }

        return $entity;
    }

    /**
     * Headers sent on every gateway webhook call. Adds the shared secret when
     * configured; otherwise empty (legacy/unprotected behaviour).
     *
     * @return array<string,string>
     */
    protected function webhookHeaders(): array
    {
        return !empty($this->secret) ? ['X-Gateway-Secret' => $this->secret] : [];
    }
}
