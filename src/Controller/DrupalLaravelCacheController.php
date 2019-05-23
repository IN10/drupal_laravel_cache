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
     * DrupalLaravelCacheController constructor.
     */
    public function __construct()
    {
        $laravelSettings = Settings::get('laravel');
        $this->laravelUrl = $laravelSettings['base_url'] . $laravelSettings['cache_endpoint'];
    }

    /**
     * Call Laravel to clear all a specific bundle (content type / tag) from cache
     * @param $entity
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function invalidateCache($entity)
    {
        // get the entity bundle
        $bundle = $entity->bundle();

        try {
            $client = new Client();
            $client->request('POST', $this->laravelUrl, [
                'form_params' => [
                    'tags' => $bundle
                ]
            ]);
            \Drupal::logger('drupal_laravel_cache')->notice('Caches invalidated for tag ' . $bundle);
        } catch (\Exception $exception) {
            \Drupal::logger('drupal_laravel_cache')->error($exception->getMessage());
            \Drupal::logger('drupal_laravel_cache')->notice('Something went wrong while invalidating Laravel cache. Please check Laravel logs for the cause.');
        }
    }

    /**
     * Call Laravel to clear all content from cache
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function clearAll()
    {
        try {
            $client = new Client();
            $client->request('POST', $this->laravelUrl);
            $message = 'Caches invalidated for all content';
            \Drupal::logger('drupal_laravel_cache')->notice($message);
        } catch (\Exception $exception) {
            \Drupal::logger('drupal_laravel_cache')->error($exception->getMessage());
            $message = 'Something went wrong while clearing the cache.';
            \Drupal::logger('drupal_laravel_cache')->notice('Something went wrong while invalidating Laravel cache. Please check Laravel logs for the cause.');
        }

        return array(
            '#markup' => t($message),
        );
    }
}
