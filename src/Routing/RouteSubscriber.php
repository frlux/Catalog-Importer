<?php

namespace Drupal\catalog_importer\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('feeds.item_list')) {
      $route->setDefaults(array(
        '_title' => 'Feed items',
        '_controller' => 'Drupal\catalog_importer\Controller\ResourceListController:listItems',
      ));
    }

  }

}