<?php

namespace Drupal\catalog_importer\Utils;

class ImporterFunctions{
  public static function catalog_importer_terms_cache($vid){
    
    $bin = $vid . "Terms";
    $cid = 'catalog_importer:' . \Drupal::languageManager()
            ->getCurrentLanguage()
            ->getId();
     if ($cache = \Drupal::cache($bin)
       ->get($cid)) {
       return $cache->data;
    //   $cache = \Drupal::cache($bin)
    //   ->deleteAll();
     }

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
      $contains = $term->get('field_contains')->getValue();
      $matches = $term->get('field_matches')->getValue();
      $starts = $term->get('field_starts')->getValue();
      $ends = $term->get('field_ends')->getValue();
      $priority = $term->get('field_priority')->getValue();
      $contains_all = $term->get('field_contains_all')->getValue();
      $search[$name]['matches'] = array();
      $search[$name]['contains'] = array();
      if(!empty($search[$name]['parent']) && $search[$name]['parent'] > 0){
        $search[$name]['parent'] = $search['terms'][$search[$name]['parent']];
      } else{
        unset($search[$name]['parent']);
      }
      
      $search[$name]['priority'] = !empty($priority) ? intval($priority[0]['value']) : 0; 
      if(empty($matches) && empty($contains) && empty($starts) && empty($ends) && empty($contains_all)){
        continue;
      }
      //Set Matches
      if(!empty($matches)){
        foreach($matches as $val){
          $search[$name]['matches'][]=strtolower($val['value']);
        }
      }
      if(!empty($contains_all)){
        foreach($contains_all as $val){
          if(strpos('--', $val['value'])){
            $val = explode("--", strtolower($val['value']));
            if(count($val) > 1){
              $search[$name]['contains_all'][]['contains'] = $val[0];
               $values = explode("|||", $val[1]);
               $search[$name]['contains_all'][]['not'] = array_map('trim', $values);
            } else {
              $search[$name]['contains_all'][]['contains'] = '';
              $values = explode("|||",$values[0]);
              $search[$name]['contains_all'][]['not'] = array_map('trim', $values);
            }
          }else{
            $val = explode("|||", strtolower($val['value']));
            $search[$name]['contains_all'][] = array_map('trim', $val);
          }
        }
      }
      //Set partial matches
      if(!empty($contains)){
        foreach($contains as $val){
          $search[$name]['contains'][]=strtolower($val['value']);
        }
      }
      //Set starts with
      if(!empty($starts)){
        foreach($starts as $val){
          $search[$name]['starts'][]=strtolower($val['value']);
        }
      }
      //Set ends with
      if(!empty($ends)){
        foreach($ends as $val){
          $search[$name]['ends'][]=strtolower($val['value']);
        }
      }

    }
    if($vid == "topic"){
      $resourceVocabs = array('genre', 'audience');

      foreach($resourceVocabs as $vocab){
        $search['terms'][$vocab] = array();
        $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocab); //, 0, NULL, TRUE

        foreach ($terms as $term) {
          $search['terms'][$vocab][$term->tid] = strtolower($term->name);
          $parents[$term->name] = (string) array_shift($term->parents);
        }
      }
    } else{
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
        $search['terms']['tree'] = $tree;
      }
    }
    uasort($search, function($a, $b) {
      return $a['priority'] <=> $b['priority'];
    });
    \Drupal::cache($bin)
        ->set($cid, $search);
    return $search;
  }
}