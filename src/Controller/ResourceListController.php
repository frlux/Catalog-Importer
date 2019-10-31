<?php

namespace Drupal\catalog_importer\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\Processor\EntityProcessorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\feeds\Controller\ItemListController;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * {@inheritdoc}
 */
class ResourceListController extends ItemListController {

  /**
   * {@inheritdoc}
   */
  public function listItems(FeedInterface $feeds_feed, Request $request) {
    $processor = $feeds_feed->getType()->getProcessor();
    
    if($processor->getPluginId() != 'evergreen_resource_processor'){
      return parent::listItems($feeds_feed,$request);
    }
    $header = [
      'id' => $this->t('ID'),
      'title' => $this->t('Label'),
      'imported' => $this->t('Imported'),
      'guid' => [
        'data' => $this->t('GUID'),
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'url' => [
        'data' => $this->t('URL'),
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
    ];
    
    $build = [];
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => [],
      '#empty' => $this->t('There are no items yet.'),
    ];

    if (!$processor instanceof EntityProcessorInterface) {
      return $build;
    }

    $entity_ids = $this->entityTypeManager()->getStorage($processor->entityType())->getQuery()
      ->condition('field_resource_importer_id', $feeds_feed->id())
      ->pager(50)
      ->sort('feeds_item.imported', 'DESC')
      ->execute();
    
    $storage = $this->entityTypeManager()->getStorage($processor->entityType());
    foreach ($storage->loadMultiple($entity_ids) as $entity) {
      $ago = $this->dateFormatter->formatInterval($this->time->getRequestTime() - $entity->get('feeds_item')->imported);
      $row = [];

      // Entity ID.
      $row[] = $entity->id();

      // Entity link.
      try {
        $row[] = [
          'data' => $entity->toLink(Unicode::truncate($entity->label(), 75, TRUE, TRUE)),
          'title' => $entity->label(),
        ];
      }
      catch (UndefinedLinkTemplateException $e) {
        $row[] = $entity->label();
      }
      // Imported ago.
      $row[] = $this->t('@time ago', ['@time' => $ago]);
      // Item GUID.
      $row[] = [
        'data' => Html::escape(Unicode::truncate($entity->get('feeds_item')->guid, 30, FALSE, TRUE)),
        'title' => $entity->get('feeds_item')->guid,
      ];
      // Item URL.
      $row[] = [
        'data' => Link::fromTextAndUrl(Html::escape(Unicode::truncate($entity->get('feeds_item')->url, 30, FALSE, TRUE)), Url::fromUri($entity->get('feeds_item')->url, array(
          'absolute'    => TRUE,
          'attributes'  => array(
            'target'    => '_blank',
          ),
        ))),
        'title' => $entity->get('feeds_item')->url,
      ];

      $build['table']['#rows'][] = $row;
    }

    $build['pager'] = ['#type' => 'pager'];
    $build['#title'] = $this->t('%title items', ['%title' => $feeds_feed->label()]);

    return $build;
  }

}
