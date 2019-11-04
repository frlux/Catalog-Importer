<?php

namespace Drupal\catalog_importer\Plugin\Tamper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tamper\Exception\TamperException;
use Drupal\tamper\TamperableItemInterface;
use Drupal\tamper\TamperBase;
use Drupal\catalog_importer\Utils\ImporterFunctions;

/**
 * Plugin implementation for catalog importer keyword matching.
 *
 * @Tamper(
 *   id = "match_keywords_to_terms",
 *   label = @Translation("Find applicable Terms by keywords"),
 *   description = @Translation("Returns the terms best matched on keywords."),
 *   category = "Custom",
 *   handle_multiples = TRUE
 * )
 */
class MatchKeywordsToTerms extends TamperBase {
  const SETTING_OPERATION = 'operation';
  const SETTING_REDUCE = 'reduce';
  public $search;
  

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config[self::SETTING_OPERATION] = 'audience';
    $config[self::SETTING_OPERATION] = FALSE;
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form[self::SETTING_OPERATION] = [
      '#type' => 'select',
      '#title' => $this->t('Vocabulary to check'),
      '#options' => $this->getOptions(),
      '#default_value' => $this->getSetting(self::SETTING_OPERATION),
    ];
    $form[self::SETTING_REDUCE] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Return single value'),
      '#default_value' => $this->getSetting(self::SETTING_REDUCE),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->setConfiguration([self::SETTING_OPERATION => $form_state->getValue(self::SETTING_OPERATION)]);
    $this->setConfiguration([self::SETTING_REDUCE => $form_state->getValue(self::SETTING_REDUCE)]);
  }

  /**
   * Get the case conversion options.
   *
   * @return array
   *   List of options, keyed by method on Drupal's unicode class.
   */
  protected function getOptions() {
    return [
      'audience' => $this->t('Audience'),
      'genre' => $this->t('Genre'),
      'topic' => $this->t('Topic'),
    ];
  }
  /**
   * {@inheritdoc}
   */
  public function tamper($data, TamperableItemInterface $item = NULL) {
    $vocab = $this->getSetting(self::SETTING_OPERATION);
    $terms = $this->processKeywords($data, $vocab); //$vocab == 'topic' && !empty($item->feedItem->genre) ? $this->processKeywords($item->feedItem->genre, 'topic') : 

    
    if($vocab == 'genre' ){
      $terms = $this->cleanupGenres($terms); 
    } 
    
    if(isset($this->search[$vocab]['terms'])){
      /**
       * @todo add option when configuring plugin to filter out vocabs from other vocabs
       * i.e. include other vocab lists in the cache for a vocab.
       */
      foreach($this->search[$vocab]['terms'] as $v => $t){
        $terms = is_array($t) ? array_diff($terms, $t) : $terms;
      }
    }

    if($this->getSetting(self::SETTING_REDUCE)){
      return $this->reduceAudience($terms, $vocab);
    } 

   return array_unique($terms);
  }
/**
 * Reduces Audience array to 1.
 */
  public function reduceAudience($audiences, $vocab){
    $terms = array_keys($this->search[$vocab]); //$terms = array_keys($this->search['audience']);
    $counts = array();
    
    foreach($terms as $term){
      $counts[$term] = 0;
    }
    
    if($vocab == 'audience'){
      $add = array_intersect($terms, $audiences);
      $check = array_diff($audiences, $terms);
      $audiences = array_merge($add, $this->checkAudienceKeywords($check, $terms));
    }
  
    foreach($audiences as $k => $aud){
      if(isset($counts[$aud])){
        $counts[$aud] = $counts[$aud] + 1;
      }
    }
    
    $check = $counts;
    $check = array_unique(array_values($check));

    if(count($check) > 1){
      arsort($counts);
      $audience = (string) array_key_first($counts);
      return $audience;
    }

    $audiences=array_unique($audiences);
    rsort($audiences);
    

    $audience = count($audiences) > 0 ? (string) array_shift($audiences) : $vocab == 'audience' ? 'adult' : '';

    return $audience;
  }
/**
 * Iterates through keyword data
 */
  public function processKeywords($data, $vid){
    if(empty($this->search) || !isset($this->search[$vid])){
      $this->search = array($vid => ImporterFunctions::catalog_importer_terms_cache($vid));
    }
    $data = $this->flatten_array($data);
    $terms = array();
    foreach ($data as $keyword){
      $terms[] = $this->lookupTerm($keyword, $vid);
    }

    return array_filter($terms);
  }
/**
 * Cross matches keyword with term data
 */
  public function lookupTerm($value, $vid) {
    $search = $this->search[$vid];

    $checkValue = trim(strtolower($value), ". / ; , : = \\ ");
    
    foreach($search as $key => $arr){
      if($key == 'terms'){
        continue;
      }
      if($checkValue == $key){
        return $key;
      } 
      
      if(in_array($checkValue, $search[$key]['matches'])){
        return $key;
      }
      if(isset($search[$key]['contains_all'])){
        foreach($search[$key]['contains_all'] as $contains){
          $set = TRUE;
          if(isset($contains['not'])){
            $set = FALSE;
            if((empty($contains['contains']) || strpos($checkValue, $contains['contains']) !== FALSE)){
              $set = TRUE;
              foreach($contains['not'] as $not){
                if(strpos($checkValue, $not) !== FALSE){
                  $set = FALSE;
                  break;
                }
              }
            }
          } else {
            foreach($contains as $c){
              if(strpos($checkValue, $c) === FALSE){
                $set = FALSE;
                break;
              }
            }
          }
          if($set === TRUE){
            return $key;
          }
        }
      }
    if(isset($search[$key]['starts'])){
        foreach($search[$key]['starts'] as $starting){
          if(substr($checkValue, 0, strlen($starting)) === $starting){
            return $key;
          }
        }
      }
      if(isset($search[$key]['ends'])){
        foreach($search[$key]['ends'] as $ending){
          if(strpos(strrev($checkValue), strrev($ending)) === 0){
            return $key;
          }
        } 
      } 
      foreach($search[$key]['contains'] as $partial){
        if(strpos($checkValue, $partial) !== FALSE){
          return $key;
        }
      }
    }
    return $checkValue;
  }
/**
 * Flattens array
 */
  protected function flatten_array($arg) {
    return is_array($arg) ? array_reduce($arg, function ($c, $a) { return array_merge($c, $this->flatten_array($a)); },[]) : [$arg];
  }
  public function cleanupGenres($keywords){
    $add = array();
    $remove = array();
    $count = array();

    foreach($this->search['genre']['terms']['tree'] as $parent => $children){
      $count[$parent] = 0;
      $check = array_intersect($children, $keywords);
      if(!empty($check)){
        $count[$parent] += count($check);
        $add[] = $parent;
      }
    }

    if(in_array('music', $keywords) && in_array('text', $keywords)){
      $remove[]='music';
    }
    foreach($keywords as $keyword){
      if(in_array($keyword, array_keys($count))){
        $count[$keyword] += 1;
      }
    }
    
    
    arsort($count);
    $check = $count;
    $check = array_unique(array_values($check));

    if($count['fiction'] == $count['nonfiction'] && ($count['fiction'] > 0 || $count['nonfiction'] > 0)){
      //do nothing?
    } elseif(count(array_filter($check)) >= 1) {
      array_shift($count);
      foreach($count as $parent => $num){
        $remove = array_merge($remove, $this->search['genre']['terms']['tree'][$parent]);
        $remove[]=$parent;
      }
    }
    
    $genres = array_unique(array_merge($keywords, $add));

    return array_diff($genres, $remove);
  }
  public function cleanupTopics($keywords){
    $topics = array();
    foreach($keywords as $keyword){
      if(!in_array($keyword, $this->search['topic']['terms']['audience']) && !in_array($keyword, $this->search['topic']['terms']['genre']) && !in_array($keyword, $topics)){
        $topics[] = $keyword;
      }
    }
    return $topics;
  }
  /**
   * Check Additional Audiences
   */
  public function checkAudienceKeywords($values, $terms){
    $array = array();
    if(empty($values)){
      return $array;
    }
    
    foreach($values as $checkValue){
      if (preg_match('/[0-9]{4}-[0-9]{1,4}/', $checkValue) ||
        preg_match('/[0-9]{4}-$/', $checkValue) ||
        (preg_match('/[0-9]+[t][h]\s/', $checkValue) && strpos($checkValue, 'century') !== FALSE)){
        continue;
      } elseif(substr($checkValue, -6) == 'and up' || substr($checkValue, -4) == '& up'){

        if(strpos($checkValue, 'grade') !==FALSE){
          preg_match('/\d+/', $checkValue, $matches);
          $array[] = empty($matches[0]) || substr($checkValue,0,1) == 'k' ? 'juvenile' : $matches[0] >= 6 ? 'young adult' : 'juvenile';
          continue;
        }
        preg_match('/\d+/', $checkValue, $matches);
        $array[] = empty($matches[0]) || substr($checkValue,0,1) == 'k' ? 'juvenile' : $matches[0] >= 12 ? 'young adult' : 'juvenile';

      } elseif (strpos($checkValue, 'juvenile') !== FALSE ||
        strpos($checkValue, 'school') !== FALSE ||
        strpos($checkValue, "children's ") !== FALSE ||
        (strpos($checkValue, "education") !== FALSE && strpos($checkValue, "primary") !== FALSE) ||
        $checkValue === "e" ||
        $checkValue === "j" ||
        $checkValue === "k-12"){
          $array[] = "juvenile";
      } elseif ($checkValue === "young adult" ||
              $checkValue === "adolescent" ||
              $checkValue === "ya" ||
              $checkValue === "grade 9+" ||
              $checkValue === 'higher education' ||
              $checkValue === 'grade 9-adult'){
          $array[] =  "young adult";
      } elseif (substr($checkValue,0,5) === 'adult' || $checkValue === "general"){
        $array[] =  "adult";
      } elseif (strpos($checkValue, 'grade') !== FALSE ||
                substr($checkValue,0,3) === 'age' ||
                strpos($checkValue, ' ages ') !== FALSE ||
                substr($checkValue,0,6) === 'infant' ||
                substr($checkValue,0,4) === 'k to' ||
                preg_match('/^[0-9]+(-[0-9]+)+$/', $checkValue) ||
                strpos($checkValue, 'lexile') !== FALSE ){
        $array[] = "juvenile";
      } elseif (strpos($checkValue, 'young adult') !== FALSE||
              strpos($checkValue, 'teen') !== FALSE ||
              strpos($checkValue, 'higher education') !== FALSE ||
              strpos($checkValue, 'rated t') !== FALSE ){
        $array[] = "young adult";
      } elseif (strpos($checkValue, 'rated g') !== FALSE ||
                strpos($checkValue, 'rating: g') !== FALSE){
        $array[] = "juvenile";
      } elseif (strpos($checkValue, 'adult') !== FALSE ||
                strpos($checkValue, 'rating: r') !== FALSE ||
                strpos($checkValue, 'rating: pg-13') !== FALSE ||
                strpos($checkValue, 'rated r') !== FALSE ||
                strpos($checkValue, 'not rated') !== FALSE){
        $array[] = "adult";
      } elseif (preg_match('/[\d]/', $checkValue) ||
                $checkValue === "education films") {
        $array[] = "juvenile";
      }
    }
    $array = array_intersect($terms, $array);
    return $array;
  }
}