<?php

namespace Drupal\catalog_importer\Feeds\Parser;

use Drupal\feeds\FeedInterface;
use Drupal\feeds\Plugin\Type\Parser\ParserInterface;
use Drupal\feeds\Plugin\Type\PluginBase;
use Drupal\feeds\Result\FetcherResultInterface;
use Drupal\feeds\StateInterface;
use Drupal\catalog_importer\Feeds\Item\ResourceItem;
use Drupal\feeds\Result\ParserResult;

/**
 * Your Class Description.
 *
 * @FeedsParser(
 *   id = "evergreen_mods_parser",
 *   title = @Translation("Evergreen Mods Parser"),
 *   description = @Translation("Parser for Evergreen Mods3 format")
 * )
 */
class EvergreenModsParser extends PluginBase implements ParserInterface {

  /**
   * {@inheritdoc}
   */
  public function parse(FeedInterface $feed, FetcherResultInterface $fetcher_result, StateInterface $state) {
    $result = new ParserResult();
    $raw = $fetcher_result->getRaw();
    $items = simplexml_load_string($raw);

    foreach ($items->mods as $item) {
      if (!empty($item)) {
        $yourItem = new ResourceItem();
        // Process out the $item into an easily usable data set.
        $yourItem->set('catalog', 'evergreen');
        $yourItem->set('guid', (string) $item->recordInfo->recordIdentifier[0]);
        $yourItem->set('tcn', (string) $item->recordInfo->recordIdentifier[0]);
        $yourItem->set('active_date', (string) $item->recordInfo->recordCreationDate);

        $titleArray=$this->processResourcesTitles($item);
        $yourItem->set('title', $titleArray[0]);
        array_shift($titleArray);
        $yourItem->set('alt_titles', $titleArray);

        $keywords = $this->getItemKeywords($item);

        foreach($titleArray as $title){
          $check = preg_match('/\[(.*)\]/U', strtolower($title), $matches);
          if($matches && count($matches)> 1){
            $keywords[] = $matches[1];
          }
          if(preg_match('/[\xE1\xE9\xED\xF3\xFA\xC1\xC9\xCD\xD3\xDA\xF1\xD1]/', $title)) {
            $keywords[] = "foreign language";
          }
        }

        $yourItem->set('audience', $keywords);
        $yourItem->set('genre', $keywords);
        $yourItem->set('topics', $keywords);

        $yourItem->set('type', $this->getItemKeywords($item, 'type'));
        $yourItem->set('form', $this->getItemKeywords($item, 'form'));
        $yourItem->set('classification', $this->getItemKeywords($item, 'ddc'));

        $authors = $this->getCreators($item);
        $yourItem->set('creators', $authors['names']);
        $yourItem->set('roles', $authors['roles']);


        $identifiers = $this->processIdentifiers($item);
        $types = array_map('strval', array_values($identifiers['identifiers']));
        $yourItem->set('identifier_types', $types);
        $ids = array_map('strval', array_keys($identifiers['identifiers']));
        $yourItem->set('identifier_ids', $ids);
        $yourItem->set('isbn', $identifiers['isbns']);

        $description = $this->getResourceDescription($item);
        $yourItem->set('description', $description);
      }
      $result->addItem($yourItem);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMappingSources() {
    return [
      'guid' => [
        'label' => $this->t('GUID'),
        'description' => $this->t('Unique ID for Feeds.'),
      ],
      'title' => [
        'label' => $this->t('Title'),
        'description' => $this->t('Resource Title'),
      ],
      'active_date' => [
        'label' => $this->t('Active Date'),
        'description' => $this->t('Date item added to catalog.'),
      ],
      'alt_titles' => [
        'label' => $this->t('Alternative Titles'),
        'description' => $this->t('Other titles for this item.'),
      ],
      'audience' => [
        'label' => $this->t('Audience'),
        'description' => $this->t('Audience indicators'),
      ],
      'creators' => [
        'label' => $this->t('Creators'),
        'description' => $this->t('Resource creators/authors'),
      ],
      'roles' => [
        'label' => $this->t('Roles'),
        'description' => $this->t('Resource creators/authors roles'),
      ],
      'description' => [
        'label' => $this->t('Description'),
        'description' => $this->t('Resource abstract/description'),
      ],
      'featured_collection' => [
        'label' => $this->t('Featured Collection'),
        'description' => $this->t('Featured Collection Terms'),
      ],
      'genre' => [
        'label' => $this->t('Genre'),
        'description' => $this->t('Genre Indicators'),
      ],
      'isbn' => [
        'label' => $this->t('ISBN'),
        'description' => $this->t('ISBN'),
      ],
      'topics' => [
        'label' => $this->t('Topics'),
        'description' => $this->t('Topic/Subject Indicators'),
      ],
      'image' => [
        'label' => $this->t('Image'),
        'description' => $this->t('url for resource image'),
      ],
      'tcn' => [
        'label' => $this->t('TCN'),
        'description' => $this->t('Record ID'),
      ],
      'url' => [
        'label' => $this->t('URL'),
        'description' => $this->t('item Record URL'),
      ],
      'identifier_ids' => [
        'label' => $this->t('Identifier IDs'),
        'description' => $this->t('item Record identifier IDs'),
      ],
      'identifier_types' => [
        'label' => $this->t('Identifier Types'),
        'description' => $this->t('item Record identifier types'),
      ],
      'catalog' => [
        'label' => $this->t('Catalog'),
        'description' => $this->t('Catalog source for this resource.'),
      ],
      'type' => [
        'label' => $this->t('Resource Type'),
        'description' => $this->t('typeOfResouce'),
      ],
      'form' => [
        'label' => $this->t('Form'),
        'description' => $this->t('Item form.'),
      ],
      'classification' => [
        'label' => $this->t('Dewey'),
        'description' => $this->t('DDC'),
      ],
    ];
  }
/**
   * Get an array of identifiers
   */
  public function processIdentifiers($item){
    $array = array(
      'identifiers' => array(),
      'isbns'       => array()
    );
    foreach($item->identifier as $identifier){
      $attributes = $identifier->attributes();
      if(isset($attributes->type)){
        $attr = (string) $attributes->type;
      } else {
        $attr = 'unk';
      }
      $identifier = (string) $identifier;
      $array['identifiers'][$identifier] = $attr;
      if(strtolower($attr) == 'isbn'){
        $isbn = preg_replace('/\D/', '', $identifier);
        if (strlen($isbn) === 9) {
          $isbn = $isbn . "X";
        }
        $array['isbns'][] = $isbn;
      }
      if(strtolower($attr) == 'upc'){
        $array['isbns'][] = $identifier;
      }
    } 
    return $array;
  }

  public function processResourcesTitles($item){
    $titleArray = array();
    foreach($item->titleInfo as $key => $title){
      if($key==0){
        $titleArray[] = (string) $this->processResourceTitle($title, TRUE); 
      }
      $t = (string) $this->processResourceTitle($title);
      if($t && !in_array($t, $titleArray)){
        $titleArray[] = $t;
      }
    }
    return $titleArray;
  }
  /**
   * Process the array of title parts and convert to string.
   * 
   * @param   object    $titleObject    SimpleXMLElement Object from "titleInfo" array
   * @param   boolean   $mainTitle      Whether this is the simplified main title; defaults false
   * 
   * @return  string    title string
   */
  public function processResourceTitle($titleObject, $mainTitle = FALSE){
    $titleArray = array();
    if($mainTitle){
      $title = isset($titleObject->nonSort)
                  ? $titleObject->nonSort . " " . $titleObject->title 
                  : (string) $titleObject->title;
      $title = trim(preg_replace('/\[.*\]/', "", $title), "= / . : ");
      return $title;
    }
   
    if(isset($titleObject->subTitle)){
      $titleArray[] = (string) $titleObject->title . ": " . (string) $titleObject->subTitle;
    } else {
      $titleArray[] = (string) $titleObject->title;
    }
    if(isset($titleObject->partName)){
      $titleArray[] = "- ". (string) $titleObject->partName;
    }
    if(isset($titleObject->partNumber)){
      $titleArray[] = "(" . (string) $titleObject->partNumber . ")";
    }
    $titleArray = array_map('trim', $titleArray);
    return implode(" ", $titleArray);
  }

  
  /**
   * Process the item object to extract keywords.
   * 
   * @param   object    $item     SimpleXMLElement Object Item
   * @param   string    $type     String indicating type of keywords to retrieve
   * 
   * @return  string    title string
   */
  public function getItemKeywords($item, $type=NULL){
    $keywordArray = array();
    
    //$this->convertToArray($titleObject);
    if(isset($item->typeOfResource) && (!$type || $type == "type")){
      foreach($item->typeOfResource as $t){
        $keywordArray[] = (string) $t;
      }
    }
    if(isset($item->physicalDescription) && (!$type || $type == "form")){
      foreach($item->physicalDescription as $p){
        foreach($p->form as $f){
          $keywordArray[] = (string) $f;
        }
      }
    }
    if(isset($item->genre) && (!$type || $type == "genre")){
      foreach($item->genre as $g){
        $keywordArray[] = (string) $g;
      }
    }
    if(isset($item->targetAudience) && (!$type || $type == "audience")){
      foreach($item->targetAudience as $a){
        $keywordArray[] = (string) $a;
      }
    }
    if(isset($item->subject) && (!$type || $type == "topic")){
      foreach($item->subject as $s){
        foreach($s->topic as $t){
          $keywordArray[] = (string) $t;
        }
      }
    }
    if(isset($item->relatedItem) && (!$type || $type == "topic")){
      foreach($item->relatedItem as $r){
        $attributes = $r->attributes();
        if(isset($attributes->type)){
          $type = (string) $attributes->type;
          if($type == 'series'){
            foreach($r->titleInfo as $t){
              $series = explode(";", (string) $t->title);
              $keywordArray[] = trim($series[0]);
            }
          }
        }
      }
    }
    if(isset($item->originInfo)){
      $edition = $this->getOriginInfo($item, 'edition');
      $keywordArray = array_merge($keywordArray, $edition);
    }
    if(isset($item->classification) && (!$type || $type == "classification" || $type == 'ddc')){
      foreach($item->classification as $c){
        $auth = '';
        $attributes = $c->attributes();
        if(isset($attributes->authority)){
          $auth = (string) $attributes->authority;
        } 
        $dewey = '';
        if($auth){
          if(strtolower($auth) == 'ddc'){
            $c = (string) $c;
            $dewey = preg_replace('/[^ .0-9]/', '', $c);
            $dewey = floatval(trim($dewey, ". "));
            $dewey = !empty($dewey) && $dewey > 0 ? $dewey : trim(strtolower(preg_replace('/[\d\/\\.\[\]]/', ' ', $dewey))); 
            if($type == "ddc" && is_numeric($dewey)){
              return $dewey;
            }
          }
          if($type == 'ddc'){
            return '';
          }
          $keywordArray[] = $this->keywordsFromClassification($c, $dewey);
        }
      }
    }
    if(isset($item->language) && (!$type || $type == "lang")){
      foreach($item->language as $l){
        foreach($l->languageTerm as $t){
          $keywordArray[] = (string) $t;
        }
      }
    }
    $keywordArray = array_map(function($item) {
                      return trim(strtolower($item), '= / . : , ; - ');
                    }, $keywordArray);
    $keywordArray = array_filter($keywordArray);
    return array_unique(array_map('strtolower', $keywordArray));
  }
  /**
   * Getting array of Creators
   */
  public function getCreators($item){
    $creators = array(
      'names' => array(),
      'roles' => array()
    );
   
    if(!isset($item->name)){
      return $creators;
    }

    foreach($item->name as $author){
      $creators['names'][] = (string) $author->namePart[0];
      $roles = array();
      foreach($author->role as $role){
        $roles[]= (string) $role->roleTerm;
      }
      $creators['roles'][] = implode(", ", array_filter($roles));
    }
  
   return $creators; 
  }
  public function getOriginInfo($item, $field=NULL){
    $array = array();
    foreach($item->originInfo as $info){
      $origin = '';

      if(isset($info->edition) && (!$field || $field == 'edition')){
        $origin .= strlen($info->edition) > 1 && !$field 
                      ? "<li>Edition: " . (string) $info->edition . "</li>" 
                      : $field
                      ? (string) $info->edition 
                      : '';
      }
      if(isset($info->dateIssued) && (!$field || $field == 'dateIssued')){
        $origin .= strlen($info->dateIssued) > 1 
                      ? "<li>Issued: " . (string) $info->dateIssued . "</li>" 
                      : $field
                      ? (string) $info->dateIssued
                      : '';
      }
      if(isset($info->dateCreated) && (!$field || $field == 'dateCreated')){
        $origin .= strlen($info->dateCreated) > 1 && !$field 
                      ? "<li>Created: " . (string) $info->dateCreated . "</li>" 
                      : $field
                      ? (string) $info->dateCreated
                      : '';
      }
      if(isset($info->copyrightDate) && (!$field || $field == 'copyrightDate')){
        $origin .= strlen($info->copyrightDate) > 1 && !$field 
                      ? "<li>Copyright: " . (string) $info->copyrightDate . "</li>" 
                      : $field
                      ? (string) $info->copyrightDate
                      : '';
      }
      if(isset($info->dateCaptured) && (!$field || $field == 'dateCaptured')){
        $origin .= strlen($info->dateCaptured) > 1 && !$field 
                      ? "<li>Captured: " . (string) $info->dateCaptured . "</li>" 
                      : $field
                      ? (string) $info->dateCaptured
                      : '';
      }
      if(isset($info->dateValid) && (!$field || $field == 'dateValid')){
        $origin .= strlen($info->dateValid) > 1 && !$field 
                      ? "<li>Valid: " . (string) $info->dateValid . "</li>" 
                      : $field
                      ? (string) $info->dateValid
                      : '';
      }
      if(isset($info->dateModified) && (!$field || $field == 'dateModified')){
        $origin .= strlen($info->dateModified) > 1 && !$field 
                      ? "<li>Modified: " . (string) $info->dateModified . "</li>" 
                      : $field
                      ? (string) $info->dateModified
                      : '';
      }
      if(isset($info->dateOther) && (!$field || $field == 'dateOther')){
        $origin .= strlen($info->dateOther) > 1 && !$field 
                      ? "<li>Dated: " . (string) $info->dateOther . "</li>" 
                      : $field
                      ? (string) $info->dateOther
                      : '';
      }
      if(isset($info->publisher) && (!$field || $field == 'publisher')){
        $publishers=array();
        foreach($info->publisher as $publisher){
          $publishers[]= (string) $publisher;
        }
        array_filter($publishers);
        if(count($publishers) > 0){
          $origin .= !$field 
                        ? "<li>Publisher: " . implode("; ", $publishers) . "</li>"
                        : implode("; ", $publishers);
        }
      }
      $origin = !$field && strlen($origin) > 1 
                  ? "<ul class='resource--originInfo'>" . $origin . "</ul>"
                  : $field 
                  ? $origin 
                  : NULL;

      $array[] = (string) $origin; 
    }
    return array_filter($array);

  }
 
  /**
   * Get the body description text
   */
  public function getResourceDescription($item){
    $body = array();
    if(isset($item->abstract)){
      foreach($item->abstract as $abstract){
        $body[]=(string) $abstract;
      }
    }
    if(isset($item->originInfo)){
      foreach($item->originInfo as $info){
        $origin = array();
        if(isset($info->edition)){
          $origin[]= strlen($info->edition) > 1 ? "Edition: " . (string) $info->edition : '';
        }
        if(isset($info->dateIssued)){
          $origin[]= strlen($info->dateIssued) > 1 ?"Issued: " . (string) $info->dateIssued : '';
        }
        if(isset($info->dateCreated)){
          $origin[]= strlen($info->dateCreated) > 1 ?"Created: " . (string) $info->dateCreated : '';
        }
        if(isset($info->copyrightDate)){
          $origin[]= strlen($info->copyrightDate) > 1 ? "Copyright: " . (string) $info->copyrightDate : '';
        }
        if(isset($info->dateCaptured)){
          $origin[]= strlen($info->dateCaptured) > 1 ? "Captured: " . (string) $info->dateCaptured : '';
        }
        if(isset($info->dateValid)){
          $origin[]= strlen($info->dateValid) > 1 ? "Valid: " . (string) $info->dateValid : '';
        }
        if(isset($info->dateModified)){
          $origin[]= strlen($info->dateModified) > 1 ? "Modified: " . (string) $info->dateModified : '';
        }
        if(isset($info->dateOther)){
          $origin[]= strlen($info->dateOther) > 1 ? "Dated: " . (string) $info->dateOther : '';
        }
        if(isset($info->publisher)){
          $publishers=array();
          foreach($info->publisher as $publisher){
            $publishers[]= (string) $publisher;
          }
          array_filter($publishers);
          if(count($publishers) > 0){
            $origin[]="Publisher: " . implode("; ", $publishers);
          }
        }
        array_filter($origin);
        if(count($origin) > 0){
          $body[]="<div class='originInfo'>" . implode("<br/>", $origin) . "</div>";
        }
      }
    }
    if(isset($item->note)){
      foreach($item->note as $note){
        $body[]=(string) $note;
      }
    }
    if(isset($item->targetAudience)){
      $aud = $this->getItemKeywords($item, 'audience');
      $body[] = implode("; ", array_filter($aud));
    }
    if(isset($item->physicalDescription)){
      foreach($item->physicalDescription as $physicalDescription){
        $description = array();
        if(isset($physicalDescription->form)){
          $description[] = (string)$physicalDescription->form;
        }
        if(isset($physicalDescription->internetMediaType)){
          $description[] = (string)$physicalDescription->internetMediaType;
        }
        if(isset($physicalDescription->extent)){
          $description[] = (string)$physicalDescription->extent;
        }
        if(isset($physicalDescription->digitalOrigin)){
          $description[] = (string)$physicalDescription->digitalOrigin;
        }
        if(isset($physicalDescription->note)){
          $description[] = (string)$physicalDescription->note;
        }
        $body[]= implode(" - ", array_filter($description));
      }
    }
    if(isset($item->accessCondition)){
      $conditions = array();
      foreach($item->accessCondition as $condition){
        $conditions[]=(string) $condition;
      }
      $body[] = implode("; ", array_filter($conditions)); 
    }
    $body = array_map("trim", $body);
    return implode("<br/><br/>", array_filter($body));
  }
  public function keywordsFromClassification($classification, $dewey = NULL){
    if($dewey && is_numeric($dewey) &&  $dewey > 0 && $dewey < 1000){
      $dNum = $dewey;
      $dText = '';
    } else{
      $dNum = preg_replace('/[^ .0-9]/', '', $classification);
      $dNum = trim($dNum, ". ");
      $dNum = floatval($dNum);
      $dText = preg_replace('/[\d\/\\.\[\]]/', ' ', $classification);
      $dText = strtolower(trim($dText));
    }
    if ($dNum > 0) {
      if ($dNum < 742 && $dNum > 739){
        return "graphic novel";
      } elseif (($dNum >= 800 && $dNum <= 899)) { 
        return "fiction";
      } elseif (($dNum >= 791 && $dNum < 793)) {
        return "video";
      } elseif(($dNum > 919 && $dNum < 921) || ($dNum > 758 && $dNum < 760) || ($dNum > 708 && $dNum < 710) || ($dNum > 608 && $dNum < 610) || ($dNum > 508 && $dNum < 510) || ($dNum > 408 && $dNum < 410) || ($dNum > 269 && $dNum < 271) || ($dNum > 108 && $dNum < 110)){
        return 'biography';
      } elseif( ($dNum > 810 && $dNum < 812) || ($dNum > 820 && $dNum < 822) || ($dNum > 830 && $dNum < 832) || ($dNum > 840 && $dNum < 842) || ($dNum > 850 && $dNum < 852) || ($dNum > 860 && $dNum < 862) || ($dNum > 870 && $dNum < 875)  || ($dNum > 880 && $dNum < 885)){
        return 'poetry';
      } elseif (($dNum >= 780 && $dNum < 788)) {
        return "music";
      } elseif(($dNum < 770 || $dNum >= 900) && $dNum < 1000){
        return 'nonfiction';
      }
    } elseif ($dText == "b" || strpos($dText, 'biography') !== FALSE) {
        return "biography";
    } elseif ($dText== "e" || strpos($dText, 'easy') !== FALSE || strpos($dText, 'picture book') !== FALSE) {
        return "easy";
    } elseif(substr($dText,0,3) === 'cd ' || strpos($dText, 'music') !== FALSE) {
        return "music";
    } elseif ((substr($dText,0,3) === 'fic' || strpos($dText, 'fiction') !== FALSE) && (strpos($dText, 'film') === FALSE || strpos($dText, 'video') === FALSE || strpos($dText, 'television') === FALSE)){
        return "fiction";
    }
    return $dText;

  }

}