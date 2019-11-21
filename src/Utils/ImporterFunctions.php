<?php
namespace Drupal\catalog_importer\Utils;

class ImporterFunctions{
  public static function catalog_importer_rebuild_term_cache($vid){
     \Drupal::cache('catalog_importer')
           ->delete($vid);
     self::catalog_importer_terms_cache($vid);
  }
  public static function catalog_importer_rebuild_all_term_caches(){
    $vocabs = \Drupal::config('catalog_importer.settings')->get('cached_vocabs');
    foreach($vocabs as $vocab){
      self::catalog_importer_rebuild_term_cache($vocab);
    }
  }
  public static function catalog_importer_rebuild_term_cache_submit($form, $form_state){
    $vid = explode("-",$form_state->getTriggeringElement()['#id'])[4];
    self::catalog_importer_rebuild_term_cache($vid);
  }
  public static function catalog_importer_terms_cache($vid){
    if ($cache = \Drupal::cache('catalog_importer')
      ->get($vid)) {
      return $cache->data;
    }

    $keyword_fields = \Drupal::config('catalog_importer.settings')->get('keyword_fields');
    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid); //, 0, NULL, TRUE
    $search = array();
    foreach ($terms as $term) {
      $search['terms']['priority'] = 10000;
      $name = strtolower($term->name);
      $search['terms'][$term->tid] = $name;
      $search[$name]['parent'] = (string) array_shift($term->parents);
    }
    foreach($search['terms'] as $tid => $name){
      if($tid == 'priority'){
        continue;
      }
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);

      $fields = array_combine( array_keys($keywords_fields), array(
        'matches',
        'starts',
        'ends',
        'contains',
        'contains_all',
        'priority'));
      foreach($fields as $field => $indicator){
        $values = $term->get($field)->getValue();
        if(empty($values)){
          switch($indicator){
            case 'matches':
            case 'contains':
              $search[$name][$indicator] = array(); break;
            case 'priority':
              $search[$name][$indicator] = 0;
          }
        } else {
          switch($indicator){
            case 'priority':
              $search[$name][$indicator] = intval($values[0]['value']); break;
            case 'matches':
            case 'starts':
            case 'ends':
            case 'contains':
              foreach($values as $val){
                $search[$name][$indicator][]=strtolower($val['value']);
              }
              break;
            case 'contains_all':
              foreach($values as $val){
                if(strpos('--', $val['value'])){
                  $val = explode("--", strtolower($val['value']));
                  if(count($val) > 1){
                    $search[$name][$indicator][]['contains'] = $val[0];
                    $values = explode("|||", $val[1]);
                    $search[$name][$indicator][]['not'] = array_map('trim', $values);
                  } else {
                    $search[$name][$indicator][]['contains'] = '';
                    $values = explode("|||",$values[0]);
                    $search[$name][$indicator][]['not'] = array_map('trim', $values);
                  }
                }else{
                  $val = explode("|||", strtolower($val['value']));
                  $search[$name][$indicator][] = array_map('trim', $val);
                }
              }
          }
        } 
      }

      if(!empty($search[$name]['parent']) && $search[$name]['parent'] > 0){
        $search[$name]['parent'] = $search['terms'][$search[$name]['parent']];
      } else{
        unset($search[$name]['parent']);
      }
    }
    
    $settings = \Drupal::config('catalog_importer.settings')->get('vocab_settings');
    if(isset($settings[$vid]) && !empty($settings[$vid]['diff'])){
      $resourceVocabs = array_keys($settings[$vid]['diff']);

      foreach($resourceVocabs as $vocab){
        $search['terms'][$vocab] = array();
        $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocab); //, 0, NULL, TRUE

        foreach ($terms as $term) {
          $search['terms'][$vocab][$term->tid] = strtolower($term->name);
          $parents[$term->name] = (string) array_shift($term->parents);
        }
      }
    } 

    $tree = array();
    foreach($search as $name => $info){
      if($name == 'terms'){
        continue;
      }
      if(isset($info['parent'])){
        $tree[$info['parent']][] = $name; 
      }
    }
    if(!empty($tree)){
      $search['terms']['catalog_importer_term_tree'] = $tree;
    }

    uasort($search, function($a, $b) {
      return $a['priority'] <=> $b['priority'];
    });
    \Drupal::cache('catalog_importer')
      ->set($vid, $search, \Drupal\Core\Cache\CacheBackendInterface::CACHE_PERMANENT);
    return $search;
  }
}