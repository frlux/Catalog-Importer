<?php
 
namespace Drupal\catalog_importer\Form;
 
use Drupal\Core\Form\ConfigFormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\catalog_importer\Utils\ImporterFunctions;
 
/**
 * Defines a form that configures forms module settings.
 */
class ConfigurationForm extends ConfigFormBase {
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'catalog_importer_settings';
  }
 
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'catalog_importer.settings',
    ];
  }
 
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $config = $this->config('catalog_importer.settings');

    $form['#tree'] = TRUE;
    $cached_vocabs = $config->get('cached_vocabs');
    $options = $this->getVocabularies();
    $form['vocab_settings'] = array(
      '#tree'   => TRUE,
      '#type'  => 'fieldset',
      '#title' => $this->t('Catalog Taxonomy settings'),
    );
    $form['vocab_settings']['actions']['delete'] = array(
      '#type' => 'submit',
      '#value' => t('Rebuild All Term Caches'),
      '#weight' => 15,
      '#submit' => array('Drupal\catalog_importer\Utils\ImporterFunctions::catalog_importer_rebuild_all_term_caches'),
      '#prefix' => '<div style="float:right;">',
      '#suffix' => '</div>',
  
     );
    $form['vocab_settings']['catalog_vocabularies'] = [
      '#tree' => TRUE,
      '#type' => 'checkboxes',
      '#title' => $this->t('Catalog Vocabularies'),
      '#default_value' => $cached_vocabs,
      '#options'  => $options,
      '#description' => $this->t('Select the vocabularies that should be cached for use with keyword matching in the Catalog Importer.'),
    ];
    
    if(!empty($cached_vocabs)){
      $settings = $config->get('vocab_settings');
      foreach($cached_vocabs as $vocab){
        $diffed = array_diff($cached_vocabs, [$vocab]);
        $form['vocab_settings']['config'][$vocab] = array(
          '#tree'   => TRUE,
          '#type'  => 'fieldset',
          '#title' => $this->t($vocab . ' settings'),
        );

        $form['vocab_settings']['config'][$vocab]['actions']['delete'] = array(
          '#type' => 'submit',
          '#value' => t('Rebuild ' . $options[$vocab] .' Cache'),
          '#submit' => array('Drupal\catalog_importer\Utils\ImporterFunctions::catalog_importer_rebuild_term_cache_submit'),
          '#prefix' => '<div style="float:right;">',
          '#suffix' => '</div>',
         );
       
        $form['vocab_settings']['config'][$vocab]['diff'] = [
          '#tree' => TRUE,
          '#type' => 'checkboxes',
          '#title' => $this->t('Vocabularies to filter out'),
          '#default_value' => !empty($settings) && isset($settings[$vocab]) && isset($settings[$vocab]['diff']) ? $settings[$vocab]['diff'] : [],
          '#options'  => $this->getVocabularies($diffed),
          '#description' => $this->t("Select the vocabularies whose terms should be filtered out when parsing terms during import. (i.e. remove genre terms so they aren't duplicated in the topic taxonomy.)"),
        ];
        
      }
    }
    return parent::buildForm($form, $form_state);
  }
public function getVocabularies($vids = null){
  $vocabularies = Vocabulary::loadMultiple($vids);
  $option = array();
  if($vocabularies){
    foreach($vocabularies as $vid => $vocab){
      $option[$vid] = $vocab->get('name');
    }
  }
  return $option;
}
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $config = $this->config('catalog_importer.settings');
    $vocabs = array();
    
    foreach($values['vocab_settings']['catalog_vocabularies'] as $vocab => $set){
      if(!empty($set)){
        $vocabs[] = $vocab;
        $vocabulary_entity = Vocabulary::load($vocab);
        foreach($config->get('keyword_fields') as $field_id => $field_label){
          catalog_importer_add_term_fields($vocabulary_entity, $field_id, $field_label);
        }
      }
    } 
    
    if(isset($values['vocab_settings']['config'])){
      $settings = $values['vocab_settings']['config'];
      foreach($settings as $vocab => &$setting){
        foreach($setting as $name => &$values){
          if(is_array($values)){
            $values = array_filter($values);
          }
        }
      }
      $config->set('vocab_settings', $settings);
    }
 
    $config->set('cached_vocabs', $vocabs);
    
    $config->save();
    return parent::submitForm($form, $form_state);
  }
 
}