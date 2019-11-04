<?php

namespace Drupal\catalog_importer\Feeds\Processor;

use Drupal\feeds\Feeds\Processor\EntityProcessorBase;
use Drupal\feeds\FeedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\feeds\Feeds\Item\ItemInterface;
use Drupal\Core\Form\FormStateInterface;

use Drupal\feeds\StateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\Query\QueryFactory;

use Drupal\feeds\Feeds\State\CleanStateInterface;

use Drupal\feeds\Plugin\Type\PluginBase;

/**
 * Defines an entity_test processor.
 *
 * @FeedsProcessor(
 *   id = "evergreen_resource_processor",
 *   title = @Translation("Evergreen Resource"),
 *   description = @Translation("Creates nodes from feed items."),
 *   entity_type = "node",
 *   arguments = {
 *     "@entity_type.manager",
 *     "@entity.query",
 *     "@entity_type.bundle.info",
 *   },
 *   form = {
 *     "configuration" = "Drupal\feeds\Feeds\Processor\Form\DefaultEntityProcessorForm",
 *     "option" = "Drupal\feeds\Feeds\Processor\Form\EntityProcessorOptionForm",
 *   },
 * )
 */
class EvergreenResourceProcessor extends EntityProcessorBase {
  protected $source_url;
  protected $feed_id;
  /**
   * Array containing field ids with previous values that should be preserved as keys and 
   * values indicating whether the previous values should be preserved as is (1) or appended to (0)
   * 
   * @todo make the configurable via form
   */
  protected $preserve = array(
    'field_resource_audience'=> 1,
    'field_resource_genre' => 1,
    'field_catalog'=> 1,
    'field_resource_isbn' => 0,
    'title' => 1,
    'status' => 1,
    'field_resource_description' => 1,
    'field_resource_image' => 1,
    'field_resource_url' => 1,
    'field_resource_importer_id' => 0,
    'field_featured_collection' => 0,
    'field_resource_keyword'  => 0,
    'feeds_item' => 0,
    'field_resource_id' => 1,
  );
  
  /**
   * {@inheritdoc}
   */
  public function process(FeedInterface $feed, ItemInterface $item, StateInterface $state) {
    // Initialize clean list if needed.
    $clean_state = $feed->getState(StateInterface::CLEAN);
    if (!$clean_state->initiated()) {
      $this->initCleanList($feed, $clean_state);
    }
    if(!$this->feed_id){
      $this->feed_id = $feed->id();
    }

    $existing_entity_id = $this->existingEntityId($feed, $item);
    $skip_existing = $this->configuration['update_existing'] == static::SKIP_EXISTING;

    // If the entity is an existing entity it must be removed from the clean
    // list.
    if ($existing_entity_id) {
      $clean_state->removeItem($existing_entity_id);
    }

    // Bulk load existing entities to save on db queries.
    if ($skip_existing && $existing_entity_id) {
      return;
    }

    // Delay building a new entity until necessary.
    if ($existing_entity_id) {
      $entity = $this->storageController->load($existing_entity_id);
    }

    $hash = $this->hash($item);
    $changed = $existing_entity_id && ($hash !== $entity->get('feeds_item')->hash);

    // Do not proceed if the item exists, has not changed, and we're not
    // forcing the update.
    if ($existing_entity_id && !$changed && !$this->configuration['skip_hash_check']) {
      return;
    }

    // Build a new entity.
    if (!$existing_entity_id) {
      $entity = $this->newEntity($feed);
    }

    try {
      // Set feeds_item values.
      $feeds_item = $entity->get('feeds_item');
      $feeds_item->target_id = $feed->id();
      $feeds_item->hash = $hash;

      // Set field values.
      $this->map($feed, $entity, $item);
      $this->entityValidate($entity);

      // This will throw an exception on failure.
      $this->entitySaveAccess($entity);
      // Set imported time.
      $entity->get('feeds_item')->imported = \Drupal::service('datetime.time')->getRequestTime();

      // And... Save! We made it.
      $this->storageController->save($entity);


      // Track progress.
      $existing_entity_id ? $state->updated++ : $state->created++;
    }

    // Something bad happened, log it.
    catch (ValidationException $e) {
      $state->failed++;
      $state->setMessage($e->getFormattedMessage(), 'warning');
    }
    catch (\Exception $e) {
      $state->failed++;
      $state->setMessage($e->getMessage(), 'warning');
    }
  }
  // /**
  //  * {@inheritdoc}
  //  */
  // public function entityLabel() {
  //   return $this->t('Resource');
  // }

  // /**
  //  * {@inheritdoc}
  //  */
  // public function entityLabelPlural() {
  //   return $this->t('Resources');
  // }
  /**
   * Bundle type this processor operates on.
   *
   * Defaults to the entity type for entities that do not define bundles.
   *
   * @return string|null
   *   The bundle type this processor operates on, or null if it is undefined.
   *
   * @todo We should be more careful about missing bundles.
   */
  public function bundle() {
    return 'resource';
  }

  /**
   * {@inheritdoc}
   */
  public function buildAdvancedForm(array $form, FormStateInterface $form_state) {
    return $form;
  }
  /**
   * Execute mapping on an item.
   *
   * This method encapsulates the central mapping functionality. When an item is
   * processed, it is passed through map() where the properties of $source_item
   * are mapped onto $target_item following the processor's mapping
   * configuration.
   */
  protected function map(FeedInterface $feed, EntityInterface $entity, ItemInterface $item) {
    $mappings = $this->feedType->getMappings();

    // Mappers add to existing fields rather than replacing them. Hence we need
    // to clear target elements of each item before mapping in case we are
    // mapping on a prepopulated item such as an existing node.
    foreach ($mappings as $mapping) {
      if ($mapping['target'] == 'feeds_item') {
        // Skip feeds item as this field gets default values before mapping.
        continue;
      }
      
      if(!in_array($mapping['target'], array_keys($this->preserve))){
        unset($entity->{$mapping['target']});
      } 
    }

    // Gather all of the values for this item.
    $source_values = [];
    foreach ($mappings as $mapping) {
      $target = $mapping['target'];
      foreach ($mapping['map'] as $column => $source) {
        
        if ($source === '') {
          // Skip empty sources.
          continue;
        }

        if (!isset($source_values[$target][$column])) {
          $source_values[$target][$column] = [];
        }

        $value = $item->get($source);
        if (!is_array($value)) {
          $source_values[$target][$column][] = $value;
        }
        else {
          $source_values[$target][$column] = array_merge($source_values[$target][$column], $value);
        }
      }
    }

    // Rearrange values into Drupal's field structure.
    $field_values = [];
    foreach ($source_values as $field => $field_value) {
      $field_values[$field] = [];
      foreach ($field_value as $column => $values) {
        // Use array_values() here to keep our $delta clean.
        foreach (array_values($values) as $delta => $value) {
          $field_values[$field][$delta][$column] = $value;
        }
      }
    }

    // Set target values.
    foreach ($mappings as $delta => $mapping) {
      $plugin = $this->feedType->getTargetPlugin($delta);

      if ( isset($field_values[$mapping['target']]) && !in_array($mapping['target'], array_keys($this->preserve)) ) {
        $plugin->setTarget($feed, $entity, $mapping['target'], $field_values[$mapping['target']]);
        continue;
      }

      if ( isset($field_values[$mapping['target']]) && !in_array($mapping['target'], array_keys(array_filter($this->preserve))) ) {
        // Get information about field config
        $multiple = $entity->get($mapping['target'])->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
        $field_type = $entity->get($mapping['target'])->getFieldDefinition()->getType();

        // If this field can't contain more than one value & is not a string or is a multi-value field,
        // we'll replace/append the old value with the new.
        $value = !$multiple && $field_type == 'string' ? $entity->get($mapping['target'])->getValue() :  $field_values[$mapping['target']];
        // Concatenate string values from old preserved & new update
        if(!$multiple && $field_type == 'string' && !empty($value)){
          $value[0]['value'] = $value[0]['value'] . " / " . $field_values[$mapping['target']][0]['value'];
        }

        $plugin->setTarget($feed, $entity, $mapping['target'], $value);

        if(!$multiple){
          continue;
        }
        $this->removeDuplicateFieldValues($entity,$mapping['target'], $field_type);
        continue;
      } 

      if ( isset($field_values[$mapping['target']]) ) {
        $value = $entity->get($mapping['target']);
        if($value){
          $value = $value->getValue();
        }
        if(empty($value)){
          $plugin->setTarget($feed, $entity, $mapping['target'], $field_values[$mapping['target']]);
        }
      }
    }
    return $entity;
  }
  /**
   * Removes duplicate values from entity
   */
  protected function removeDuplicateFieldValues($entity, $field, $field_type){
    $values = $entity->get($field)->getValue();

    $newValues = array();

    foreach($values as $key => $value){
      if($key == 0){
        $newValues[]=$value;
      } elseif($field_type == 'entity_reference' && !in_array($value['target_id'], array_column($newValues, 'target_id')) ){
        $newValues[]=$value;
      } elseif($field_type !== 'entity_reference' && !in_array($value['value'], array_column($newValues, 'value'))){
        $newValues[]=$value;
      }
    }
    $entity->set($field, $newValues);
  }
/**
   * Initializes the list of entities to clean.
   *
   * This populates $state->cleanList with all existing entities previously
   * imported from the source.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed to import.
   * @param \Drupal\feeds\Feeds\State\CleanStateInterface $state
   *   The state of the clean stage.
   */
  protected function initCleanList(FeedInterface $feed, CleanStateInterface $state) {
    $state->setEntityTypeId($this->entityType());

    // Fill the list only if needed.
    if ($this->getConfiguration('update_non_existent') === static::KEEP_NON_EXISTENT) {
      return;
    }

    // Set list of entities to clean.
    $ids = $this->queryFactory->get($this->entityType())
      ->condition('feeds_item.target_id', $feed->id())
      ->condition('feeds_item.hash', $this->getConfiguration('update_non_existent'), '<>')
      ->execute();
    $state->setList($ids);

    // And set progress.
    $state->total = $state->count();
    $state->progress($state->total, 0);
  }

  /**
   * {@inheritdoc}
   */
  public function clean(FeedInterface $feed, EntityInterface $entity, CleanStateInterface $state) {
    // if(!$this->source_url){
    //   $this->source_url = strtolower($feed->getSource());
    // } 
    
    // if(!$this->feed_id){
    //   $this->feed_id = $feed->id();
    // } 
    // $update_non_existent = $this->getConfiguration('update_non_existent');
    
    // if ($update_non_existent === static::KEEP_NON_EXISTENT) {
    //   // No action to take on this entity.
    //   return;
    // }

    $fc = $feed->get('field_import_featured_collection')->getValue();
    $entity_ids = array($entity->id());
    $this->removeFeaturedCollections($entity_ids, $fc);
  

    // State progress.
    $state->updated++;
    $state->progress($state->total, $state->updated);
  }
  /**
   * {@inheritdoc}
   */
  public function clear(FeedInterface $feed, StateInterface $state) {
    // Build base select statement.
    $query = $this->queryFactory->get($this->entityType())
      ->condition('field_resource_importer_id', $feed->id());

    // If there is no total, query it.
    if (!$state->total) {
      $count_query = clone $query;
      $state->total = (int) $count_query->count()->execute();
    }

    // Delete a batch of entities.
    $entity_ids = $query->range(0, 10)->execute();

    // This runs if feeds is configured to "delete missing items"
    // Resource items aren't actually deleted, but removed from bookbag
    // and references to importer & featured collection are cleaned
    if ($entity_ids) {
      $fc = $feed->get('field_import_featured_collection')->getValue();
      $this->removeFeaturedCollections($entity_ids, $fc);
      $state->deleted += count($entity_ids);
      $state->progress($state->total, $state->deleted);
    }
  }
  /**
   * {@inheritdoc}
   */
  protected function removeFeaturedCollections(array $entity_ids, $fc) {

    $entities = $this->storageController->loadMultiple($entity_ids);
    foreach($entities as $entity){
      $feeds_item = $entity->get('feeds_item')->getString();
      $feeds= $entity->get('field_resource_importer_id')->getValue();
      $collection= $entity->get('field_featured_collection')->getValue();

        
      $new_feeds = array();
      foreach($feeds as $key => $feed){
        if($feed['value'] != $this->feed_id){
          $new_feeds[] = $feed;
        }
      }

      $new_collections = array();
      foreach($fc as $key=>$featured){
        foreach($collection as $k=>$c){
          if($featured['target_id'] != $c['target_id']){
            $new_collection[] = $c;
          }
        }
      }
      if($this->feed_id == $feeds_item){
        unset($entity->feeds_item);
      }

      $entity->set('field_resource_importer_id', $new_feeds);
      $entity->set('field_featured_collection', $new_collections);
      $entity->setNewRevision(FALSE);
      $entity->save();
    }
  }

  /**
   * Returns an existing entity id.
   *
   * @param \Drupal\feeds\FeedInterface $feed
   *   The feed being processed.
   * @param \Drupal\feeds\Feeds\Item\ItemInterface $item
   *   The item to find existing ids for.
   *
   * @return int|string|null
   *   The ID of the entity, or null if not found.
   */
  protected function existingEntityId(FeedInterface $feed, ItemInterface $item) {
    foreach ($this->feedType->getMappings() as $delta => $mapping) {
      if (empty($mapping['unique'])) {
        continue;
      }
      foreach ($mapping['unique'] as $key => $true) {
        if ($mapping['target'] == 'field_resource_id') {
          $plugin = $this->feedType->getTargetPlugin($delta);
          $entity_id = $plugin->getUniqueValue($feed, $mapping['target'], $key, $item->get($mapping['map'][$key]));
          if ($entity_id) {
            return $entity_id;
          }
        }
        
      }
      
    }
  }
}