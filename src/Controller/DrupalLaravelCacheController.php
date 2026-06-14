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

    public function __construct()
    {
        $laravelSettings = Settings::get('laravel');
        $this->laravelUrl = $laravelSettings['base_url'] . $laravelSettings['cache_endpoint'];
        $this->bundleMap = isset($laravelSettings['bundle_map']) && is_array($laravelSettings['bundle_map'])
            ? $laravelSettings['bundle_map']
            : [];
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
}
