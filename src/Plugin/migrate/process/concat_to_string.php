<?php

namespace Drupal\catalog_importer\Plugin\migrate\process;

use DrupalmigrateProcessPluginBase;
use DrupalmigrateMigrateExecutableInterface;
use DrupalmigrateRow;

/**
 * Returns an href url string from the source string if link markup exists.
 *
 * Example:
 *
 * @code
 * process:
 *   field_your_field_name:
 *     -
 *       plugin: concat_to_string
 *       source: some_source_value
 * @endcode
 *
 * This will concatenate array values into a string
 *
 * @see DrupalmigratePluginMigrateProcessInterface
 *
 * @MigrateProcessPlugin(
 *   id = "concat_to_string"
 * )
 */
class yourPluginName extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // If the $value field which is the source value is a string add hello world to the end of it.
    if (!is_string($value)) {
        $val = array_filter($value);
        return implode(" ", $val);
    }
    return $value;
  }
}