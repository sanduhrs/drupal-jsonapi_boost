<?php

namespace Drupal\jsonapi_boost\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RenderCacheInterface;
use Drupal\jsonapi\EventSubscriber\NormalizationCacherInterface;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi_boost\CacheableDependenciesMergerTrait;
use Drupal\jsonapi\ResourceType\ResourceType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Caches entity normalizations after the response has been sent.
 *
 * @internal
 * @see \Drupal\jsonapi\Normalizer\ResourceObjectNormalizer::getNormalization()
 */
class EntityNormalizationCacher implements EventSubscriberInterface, NormalizationCacherInterface {

  use CacheableDependenciesMergerTrait;

  /**
   * The render cache.
   *
   * @var \Drupal\Core\Render\RenderCacheInterface
   */
  protected $renderCache;

  /**
   * The things to cache after the response has been sent.
   *
   * @var array
   */
  protected $toCache = [];

  /**
   * Sets the render cache service.
   *
   * @param \Drupal\Core\Render\RenderCacheInterface $render_cache
   *   The render cache.
   */
  public function setRenderCache(RenderCacheInterface $render_cache) {
    $this->renderCache = $render_cache;
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber::renderArrayToResponse()
   * @todo Refactor/remove once https://www.drupal.org/node/2551419 lands.
   */
  public function get(ResourceType $resource_type, ResourceObject $object) {
    $cached = $this->renderCache->get(static::generateLookupRenderArray($resource_type, $object));
    if ($cached) {
      return $cached['#data'];
    }
    else {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveLater(ResourceType $resource_type, ResourceObject $object, array $normalization_parts) {
    $key = $resource_type->getTypeName() . ':' . $object->getId();
    $this->toCache[$key] = [$resource_type, $object, $normalization_parts];
  }

  /**
   * Writes normalizations of entities to cache, if any were created.
   *
   * @param \Symfony\Component\HttpKernel\Event\PostResponseEvent $event
   *   The Event to process.
   */
  public function onTerminate(PostResponseEvent $event) {
    foreach ($this->toCache as $value) {
      list($resource_type, $object, $normalization_parts) = $value;
      $this->set($resource_type, $object, $normalization_parts);
    }
  }

  /**
   * Writes a normalization to cache.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The resource type for which to generate a cache item.
   * @param \Drupal\jsonapi\JsonApiResource\ResourceObject $object
   *   The resource object for which to generate a cache item.
   * @param array $normalization_parts
   *   The normalization parts to cache.
   *
   * @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber::responseToRenderArray()
   * @todo Refactor/remove once https://www.drupal.org/node/2551419 lands.
   */
  protected function set(ResourceType $resource_type, ResourceObject $object, array $normalization_parts) {
    assert(array_keys($normalization_parts) === ['base', 'fields']);
    $base = static::generateLookupRenderArray($resource_type, $object);
    $data_as_render_array = $base + [
        // The data we actually care about.
        '#data' => $normalization_parts,
        // Tell RenderCache to cache the #data property: the data we actually care
        // about.
        '#cache_properties' => ['#data'],
        // These exist only to fulfill the requirements of the RenderCache, which
        // is designed to work with render arrays only. We don't care about these.
        '#markup' => '',
        '#attached' => '',
      ];

    // Merge the entity's cacheability metadata with that of the normalization
    // parts, so that RenderCache can take care of cache redirects for us.
    CacheableMetadata::createFromObject($object)
      ->merge(static::mergeCacheableDependencies($normalization_parts['fields']))
      ->applyTo($data_as_render_array);

    $this->renderCache->set($data_as_render_array, $base);
  }

  /**
   * Generates a lookup render array for a normalization.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The resource type for which to generate a cache item.
   * @param \Drupal\jsonapi\JsonApiResource\ResourceObject $object
   *   The resource object for which to generate a cache item.
   *
   * @return array
   *   A render array for use with the RenderCache service.
   *
   * @see \Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber::$dynamicPageCacheRedirectRenderArray
   */
  protected static function generateLookupRenderArray(ResourceType $resource_type, ResourceObject $object) {
    return [
      '#cache' => [
        'keys' => [$resource_type->getTypeName(), $object->getId()],
        'bin' => 'jsonapi_normalizations',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::TERMINATE][] = ['onTerminate'];
    return $events;
  }

}
