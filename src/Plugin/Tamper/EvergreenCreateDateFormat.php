<?php

namespace Drupal\catalog_importer\Plugin\Tamper;

use Drupal\tamper\Exception\TamperException;
use Drupal\tamper\TamperableItemInterface;
use Drupal\tamper\TamperBase;

/**
 * Plugin implementation for strtotime.
 *
 * @Tamper(
 *   id = "evergreen_create_date_format",
 *   label = @Translation("Format Evergreen Create Date"),
 *   description = @Translation("This will take a string containing an Evergreen Record creation date and convert it into a format for the resource content type active date field."),
 *   category = "Date/time"
 * )
 */
class EvergreenCreateDateFormat extends TamperBase {

  /**
   * {@inheritdoc}
   */
  public function tamper($data, TamperableItemInterface $item = NULL) {
    if (!is_string($data)) {
      throw new TamperException('Input should be a string.');
    }
    if($data == '000000'){
      return date('Y-m-d H:i:s', strtotime('1970-01-01'));
    }
    $year = strlen($data) ===8 ? substr($data, 0, 4) : substr($data, 0, 2);
    $month = strlen($data) ===8 ? substr($data, 4, 2) : substr($data, 2, 2);
    $day = strlen($data) ===8 ? substr($data, 6, 2) : substr($data, 4, 2);
    if(strlen($year) <3){
      if(intval($year) > 35){
        $year = '19' . $year;
      } else {
        $year = '20' . $year;
      }
  }
    $dateString = $year . "-" . $month . "-" . $day;
    return date('Y-m-d H:i:s', strtotime($dateString));
  }

}
