<?php

namespace Drupal\jsonapi_boost\Plugin\warmer;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\jsonapi_extras\EntityToJsonApi;
use Drupal\warmer\Plugin\WarmerPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Warms JSON:API resources.
 *
 * @Warmer(
 *   id = "jsonapi",
 *   label = @Translation("JSON:API Resources"),
 *   description = @Translation("Warms normalizations of JSON:API resources."),
 * )
 */
final class ResourceWarmer extends WarmerPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The resource type repository.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface
   */
  private $resourceTypeRepository;

  /**
   * The entity to JSON:API service.
   *
   * @var \Drupal\jsonapi_extras\EntityToJsonApi
   */
  private $entityToJsonapi;

  /**
   * The list of all item IDs for all entities in the system.
   *
   * Consists of <entity-type-id>:<entity-id>.
   *
   * @var array
   */
  private $iids = [];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Drupal\jsonapi_boost\Plugin\warmer\ResourceWarmer $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setEntityTypeManager($container->get('entity_type.manager'));
    $instance->setResourceTypeRepository($container->get('jsonapi.resource_type.repository'));
    $instance->setEntityToJsonapi($container->get('jsonapi_extras.entity.to_jsonapi'));
    return $instance;
  }

  /**
   * Injects the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Injects the resource type repository.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $resource_type_repository
   *   The entity type manager.
   */
  public function setResourceTypeRepository(ResourceTypeRepositoryInterface $resource_type_repository) {
    $this->resourceTypeRepository = $resource_type_repository;
  }

  /**
   * Injects the entity to JSON:API service.
   *
   * @param \Drupal\jsonapi_extras\EntityToJsonApi $entitity_to_jsonapi
   *   The entity type manager.
   */
  public function setEntityToJsonapi(EntityToJsonApi $entitity_to_jsonapi) {
    $this->entityToJsonapi = $entitity_to_jsonapi;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = []) {
    $ids_per_type = array_reduce($ids, function ($carry, $id) {
      list($entity_type_id, $entity_id, ) = explode(':', $id);
      if (empty($carry[$entity_type_id])) {
        $carry[$entity_type_id] = [];
      }
      $carry[$entity_type_id][] = $entity_id;
      return $carry;
    }, []);
    $output = [];
    foreach ($ids_per_type as $entity_type_id => $entity_ids) {
      $output += $this->entityTypeManager
        ->getStorage($entity_type_id)
        ->loadMultiple($entity_ids);
    }
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function warmMultiple(array $items = []) {
    $normalizations = array_map(function (EntityInterface $entity) {
      return \Drupal::service('jsonapi_extras.entity.to_jsonapi')->normalize($entity);
    }, $items);
    count($normalizations);
  }

  /**
   * {@inheritdoc}
   */
  public function buildIdsBatch($cursor) {
    $configuration = $this->getConfiguration();
    if (empty($this->iids) && !empty($configuration['resource_types'])) {
      $resource_type_ids = array_filter(array_values($configuration['resource_types']));
      sort($resource_type_ids);
      $this->iids = array_reduce($resource_type_ids, function ($iids, $resource_type_name) {
        $resource_type = $this->resourceTypeRepository->getByTypeName($resource_type_name);
        $entity_type_id = $resource_type->getEntityTypeId();
        $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
        $bundle_key = $entity_type->getKey('bundle');
        $id_key = $entity_type->getKey('id');
        $query = $this->entityTypeManager
          ->getStorage($entity_type_id)
          ->getQuery();
        if ($id_key) {
          $query->sort($id_key);
        }
        if ($bundle_key) {
          $query->condition($bundle_key, $resource_type->getBundle());
        }
        $results = $query->execute();
        $entity_ids = array_filter((array) array_values($results));
        return array_unique(array_merge($iids, array_map(
          function ($id) use ($resource_type_name, $entity_type_id) {
            return sprintf('%s:%s:%s', $entity_type_id, $id, $resource_type_name);
          },
          $entity_ids
        )));
      }, []);
    }
    $cursor_position = is_null($cursor) ? -1 : array_search($cursor, $this->iids);
    if ($cursor_position === FALSE) {
      return [];
    }
    return array_slice($this->iids, $cursor_position + 1, (int) $this->getBatchSize());
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $options = [];
    foreach ($this->resourceTypeRepository->all() as $resource_type) {
      $options[$resource_type->getTypeName()] = $resource_type->getPath();
    }
    $configuration = $this->getConfiguration();
    $plugin_id = $configuration['id'];
    $form[$plugin_id]['resource_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Resource Types'),
      '#description' => $this->t('Enable the JSON:API resource types to warm asynchronously.'),
      '#options' => $options,
      '#default_value' => empty($configuration['resource_types']) ? [] : $configuration['resource_types'],
    ];

    return $form;
  }

}
