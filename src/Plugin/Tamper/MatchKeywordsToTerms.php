<?php

namespace Drupal\catalog_importer\Plugin\Tamper;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tamper\Exception\TamperException;
use Drupal\tamper\TamperableItemInterface;
use Drupal\tamper\TamperBase;
use Drupal\catalog_importer\Utils\ImporterFunctions;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;

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
  const SETTING_EXCLUSIVE = 'exclusive';
  public $search;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config[self::SETTING_OPERATION] = '';//'audience'; //'';
    $config[self::SETTING_REDUCE] = FALSE;
    $config[self::SETTING_EXCLUSIVE] = [];
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $tax = $this->getSetting(self::SETTING_OPERATION);
    if(empty($tax) && $cache = \Drupal::cache('catalog_importer')
       ->get('temp_vocab')){
         $tax = $cache->data;
       }

    $exclude = $this->getSetting(self::SETTING_EXCLUSIVE);
    
    if(!empty($exclude)){
      foreach($exclude as $key => &$e){
        $e = Term::load($e['target_id']);//\Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($e['target_id']); //\Drupal\taxonomy\Entity\Term::load($id);
      }
    }

    $form[self::SETTING_OPERATION] = [
      '#required' => TRUE,
      '#type' => 'select',
      '#title' => $this->t('Vocabulary to check'),
      "#empty_option"=>t('- Select -'),
      '#options' => $this->getOptions(),
      '#default_value' => $this->getSetting(self::SETTING_OPERATION),
      '#ajax' => [
        'callback' => [$this, 'setVocabulary'],
        'wrapper' => 'plugin-config', // This element is updated with this AJAX callback.
      ]
    ];
    
    $form[self::SETTING_REDUCE] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Return single value'),
      '#default_value' => $this->getSetting(self::SETTING_REDUCE),
    ];

      $form[self::SETTING_EXCLUSIVE] = [
        '#title' => $this->t('Exclusionary Terms'),
        '#description' => $this->t('For vocabularies with hierarchical structure, you may require that items belong to one tree or another, but not both. You can enter the parent terms here that should be checked or children counted to exlude the lesser tree'),
        '#type' => 'entity_autocomplete',
        '#prefix' => '<div id="select-terms">',
        '#suffix' => '</div>',
        "#target_type"  => 'taxonomy_term',
        '#tags' => TRUE,
        '#default_value' => $exclude,
        '#selection_settings' => array(
          'target_bundles' => array($tax),
        ),
      ];

      $form['actions']['add_exclusions'] = [
        '#type' => 'button',
        '#value' => $this->t('Refresh term selection'),
        '#ajax' => [
          'event' => 'mousedown',
          'callback' => [$this, 'refreshExclusiveTermSelect'],
          'wrapper' => 'select-terms', // This element is updated with this AJAX callback.
        ],
      ];

    
    return $form;
  }
  public function setVocabulary(array &$form, FormStateInterface $form_state){
    if ($cache = \Drupal::cache('catalog_importer')
       ->get('temp_vocab')){
         $cached_tax = $cache->data;
       } else{
         $cached_tax = '';
       }
    
    $tax=$form['plugin_configuration']['operation']['#value'];

    if($cached_tax !== $tax && !empty($tax)){
      \Drupal::cache('catalog_importer')
        ->set('temp_vocab', $tax, REQUEST_TIME + 60*15);
    }
    
    return $form['plugin_configuration'];
  }
  public function refreshExclusiveTermSelect(array &$form, FormStateInterface $form_state) {
    return $form['plugin_configuration'][self::SETTING_EXCLUSIVE];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {    
    parent::submitConfigurationForm($form, $form_state);
    $this->setConfiguration([
      self::SETTING_OPERATION => $form_state->getValue(self::SETTING_OPERATION),
      self::SETTING_REDUCE => $form_state->getValue(self::SETTING_REDUCE),
      self::SETTING_EXCLUSIVE => $form_state->getValue(self::SETTING_EXCLUSIVE),
    ]);
  }

  /**
   * Get the case conversion options.
   *
   * @return array
   *   List of options, keyed by method on Drupal's unicode class.
   */
  protected function getOptions() {
    $vocabularies = Vocabulary::loadMultiple();
    $config = \Drupal::config('catalog_importer.settings')->get('cached_vocabs');
    $option = array();
    foreach($vocabularies as $vid => $vocab){
      if(in_array($vid, $config)){
        $option[$vid] = $vocab->get('name');
      }
    }
    return $option;
  }
  /**
   * {@inheritdoc}
   */
  public function tamper($data, TamperableItemInterface $item = NULL) {
    $vocab = $this->getSetting(self::SETTING_OPERATION);
    $catalog_vocabularies = \Drupal::config('catalog_importer.settings')->get('cached_vocabs');

    if(!$catalog_vocabularies || !in_array($vocab, $catalog_vocabularies)){
      $msg1 = !empty($catalog_vocbularies) && is_array($catalog_vocabularies) ?
        'Currently configured vocabularies include:' . implode(", ", $catalog_vocabularies) . "." :
        'There are no vocabularies configured.';

      $message = 'Tamper plugin @label did not process, as @vocab is not marked as a "catalog vocabulary." ';
      $message .= $msg1;
      $message .= ' Please visit Configuration > Web Services > Catalog Importer Configuration to setup.';
      $message .= ' Data will be returned from this plugin without tampering.';

      \Drupal::logger('catalog_importer')->error($message, array(
              '@label'  => $this->getSetting('label'),
              '@vocab' => $vocab,
          ));

      return $data;
    }
    $exclusions = $this->getSetting(self::SETTING_EXCLUSIVE);
    $terms = $this->processKeywords($data, $vocab);
    
    if(!empty($exclusions)){//if($vocab == 'genre' ){
      if(!isset($this->search[$vocab]['terms']) || !isset($this->search[$vocab]['terms']['catalog_importer_term_tree'])){
        $message = 'Tamper plugin @label did not process @vocab exclusions as the term cache indicates this vocabulary is not hierarchical.';
        $message .= ' Please visit Configuration > Web Services > Catalog Importer Configuration to rebuild the term cache';
        $message .= ' or double check that your vocabulary term list is nested hierarchically.';

        \Drupal::logger('catalog_importer') ->error($message, array(
              '@label'  => $this->getSetting('label'),
              '@vocab' => $vocab,
          ));
      }else{
        $exclude = array();
        foreach($exclusions as $key => $e){
          $exclude[$e['target_id']] = Term::load($e['target_id'])->getName();
        }
        $terms = $this->cleanupGenres($terms, $vocab, $exclude);
      }
    } 
    /**
     * @todo add option when configuring plugin to filter out vocabs from other vocabs
     * i.e. include other vocab lists in the cache for a vocab.
     */
    if(count($this->search[$vocab]['terms']) > 1){
      foreach($this->search[$vocab]['terms'] as $v => $t){
        $terms = is_array($t) && $v != 'catalog_importer_term_tree' ? array_diff($terms, $t) : $terms;
      }
    }

    // if($this->getSetting(self::SETTING_REDUCE)){
    //   return $this->reduceAudience($terms, $vocab);
    // } 

   return $this->getSetting(self::SETTING_REDUCE) ? $this->reduceAudience($terms, $vocab) : array_unique($terms);
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
    \Drupal::logger('catalog_importer')->notice('ReduceAudience <pre>@exclude</pre>', array(
      '@exclude'  => print_r($counts, TRUE),
    ));

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
  public function cleanupGenres($keywords, $vid, $exclude){
    $add = array();
    //$remove = array();
    $count = array();
    $genres = $keywords;

    if($vid == 'genre' && in_array('music', $genres) && in_array('text', $genres)){
      $genres = array_diff($genres, ['music']);
    }

    foreach($this->search[$vid]['terms']['catalog_importer_term_tree'] as $parent => $children){
      $check = array_intersect($children, $genres);
      if(in_array($parent, $exclude)){
        $count[$parent] = 0;
      }
      if(!empty($check)){
        $add[] = $parent;
      }
      if(isset($count[$parent])){
        $count[$parent] += count($check);
      }
    }

    $genres = array_unique(array_merge($genres, $add));

    foreach($genres as $keyword){
      if(in_array($keyword, array_keys($count))){
        $count[$keyword] += 1;
      }
    }

    arsort($count);
    $check = $count;
    $check = array_unique(array_values($check));
  
    //filter out exclusionary term trees
    //$parent_count = array();
    //$parent_count = array_map(array($this,'countParents'), $count)


// foreach($exclude as $id => $name){
//   if(in_array(array_keys($count), $name){
//     $parent_count[$count[$name]] = $name;
//   }
// }
\Drupal::logger('catalog_importer')->notice('cleanupGenres <pre>@exclude</pre>', array(
  '@exclude'  => print_r($count, TRUE),
));
    if(count(array_filter($check))>=1 && $count[0] != $count[1]){
      array_shift($count);
      foreach($count as $parent => $num){
        //$remove = array_merge($remove, $this->search[$vid]['terms']['catalog_importer_term_tree'][$parent]);
        $genres = array_diff($genres, $this->search[$vid]['terms']['catalog_importer_term_tree'][$parent], [$parent]);
        //$remove[]=$parent;
      }
    }
    // if($count['fiction'] == $count['nonfiction'] && ($count['fiction'] > 0 || $count['nonfiction'] > 0)){
    //   //do nothing?
    // } elseif(count(array_filter($check)) >= 1) {
    //   array_shift($count);
    //   foreach($count as $parent => $num){
    //     $remove = array_merge($remove, $this->search[$vid]['terms']['catalog_importer_term_tree'][$parent]);
    //     $remove[]=$parent;
    //   }
    // }
    
    

    return $genres; //array_diff($genres, $remove);
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
  public function countParents($name, $count){


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