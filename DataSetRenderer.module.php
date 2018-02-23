<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * Rendering module for data sets
 * 
 * Provides rendering functions for the DataSet module.
 * 
 * TODO: This needs to be completely rewritten.
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
 
class DataSetRenderer extends WireData implements Module {
  // letter substitutions in user input (what => with)
  // the result is used in indices and selectors
  public $letterSubstitutions = array(
    'á' => 'a',
    'é' => 'e',
    'í' => 'i',
    'ó' => 'o',
    'ő' => 'ö',
    'ú' => 'u',
    'ű' => 'ü',
  );
  // default initial letters for navigation trees
  public $initialLetters = array(
    'a' => 'a, á',
    'b' => 'b',
    'c' => 'c',
    'cs' => 'cs',
    'd' => 'd',
    'dz' => 'dz',
    'dzs' => 'dzs',
    'e' => 'e, é',
    'f' => 'f',
    'g' => 'g',
    'gy' => 'gy',
    'h' => 'h',
    'i' => 'i, í',
    'j' => 'j',
    'k' => 'k',
    'l' => 'l',
    'ly' => 'ly',
    'm' => 'm',
    'n' => 'n',
    'ny' => 'ny',
    'o' => 'o, ó',
    'ö' => 'ö, ő',
    'p' => 'p',
    'q' => 'q',
    'r' => 'r',
    's' => 's',
    'sz' => 'sz',
    't' => 't',
    'ty' => 'ty',
    'u' => 'u, ú',
    'ü' => 'ü, ű',
    'v' => 'v',
    'w' => 'w',
    'x' => 'x',
    'y' => 'y',
    'z' => 'z',
    'zs' => 'zs',
    '-' => '-',
    '*' => '*',
    '$' => '$',
    '+' => '+',
  );

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

  /**
   * Called only when this module is installed
   * 
   * Creates new custom database table for storing import configuration data.
   */
  public function ___install() {
  }


  /**
   * Called only when this module is uninstalled
   * 
   * Drops database table created during installation.
   */
  public function ___uninstall() {
  }


  /**
   * Initialization
   * 
   * This function attaches a hook for page save and decodes module options.
   */
  public function init() {
  }


/***********************************************************************
 * RENDERING FUNCTIONS
 **********************************************************************/

  /**
   * Render a navigation tree for datasets based on initial letters
   * 
   * @param $dataSetPage data set page object
   * @param $pattern array initial letters for the tree or string to prepend to letters in the default array
   * @param $liClass additional attributes for <li> tags. If null <li> is omitted.
   * @param $aClass additional attributes for <a> tags. If null <a> is omitted.
   * @param $countHeadwords count the headwords matching the pattern (also skips empty sets)
   * @returns html string to output
   */
  public function renderLetterNav($dataSetPage, $pattern, $liClass=' class="nav-item"', $aClass=' class="nav-link"', $countHeadwords = false) {
    $out = ''; $initial = '';

    // always use the default language for listing headwords
    // TODO multilanguage data sets are not supported atm
    $lang = $this->languages->get('default');

    if (is_array($pattern)) {
      // print out these letters
      $letters = $pattern;
    } else if (is_string($pattern) && mb_strlen($pattern)) {
      // sanitizing pattern ($sanitizer->selectorValue() would not work well)
      $pattern = str_replace('"', '', $pattern);
      // assemble a set of letters for the menu
      $letters = array(); $substr = '';
      // add increasing number of starting letters of $pattern: 1 12 123 1234 ...
      foreach (preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) as $letter) {
        $substr .= $letter;
        $index = mb_strtolower($substr);
        if (isset($this->dictInitialLetters[$index])) {
          // if the substring found in the default letters, get its qualified name
          // this is the case with double and triple letters like sz, cs, dzs etc.
          $letters[$index] = $this->dictInitialLetters[$index];
          $initial = $index;
        } else {
          $letters[$index] = $substr;
        }
      }
      // add possible letters after $pattern from the default letter set
      foreach ($this->initialLetters as $u => $l) {
        $index = mb_strtolower($pattern).$u;
        $letters[$index] = $index;
      }
    } else { // empty or invalid pattern, use the default letter set
      $letters = $this->initialLetters;
    }

    foreach ($letters as $u => $t) {
      if (!is_null($liClass)) $out .= "<li$liClass>";
      $url = urlencode($u);
      $text = $t;
      $selector = $u;
      // for the active nav item add an extra span wrapper (TODO: this is a hack, avoid it)
      if ($initial == $u) {
        $text = '<span class="bg-primary text-white mx-2"> '.$text.' </span>';
      }
      // TODO always use the default language for querying headwords
      $count = $this->pages->count('parent='.$dataSetPage.',title^="'.$selector.'"');
      if ($count == 0) continue;
      if ($countHeadwords) $text .= " ($count)";
      $out .= "<a href='{$dataSetPage->url}?w={$url}'{$aClass}>{$text}</a>";
      if (!is_null($liClass)) $out .= '</li>';
      $out .= "\n";
    }

    return $out;
  }

  /**
   * Display a navigation tree for dataSet items
   * 
   * @param $dataSetPage dataSet page object
   * @param $letters array initial letters for the tree
   * @param $liClass additional attributes for <li> tags. If null <li> is omitted.
   * @param $aClass additional attributes for <a> tags. If null <a> is omitted.
   * @returns html string to output
   */
  public function renderDataNav($dataSetPage, $selector='', $liClass=' class="nav-item"', $aClass=' class="nav-link"') {
    $out = '';
    // always use the default language for listing headwords
    // TODO multilanguage dictionaries are not supported atm
    $lang = $this->user->language;
    $this->user->language = $this->languages->get('default');

    $headwords = $dataSetPage->children($selector);
    foreach ($headwords as $headword) {
      if (!is_null($liClass)) $out .= "<li$liClass>";
      if (is_null($aClass)) {
        $out .= $t;
      } else {
        $out .= "<a href='{$headword->url}'{$aClass}>".str_replace('$', '|', $headword->title)."</a>";
      }
      if (!is_null($liClass)) $out .= '</li>';
      $out .= "\n";
    }

    // restore the original language
    $this->user->language = $lang;

    return $out;
  }

  /**
   * Display a navigation tree for dataSet items
   * 
   * @param $dataSetPage dataSet page object
   * @param $letters array initial letters for the tree
   * @param $liClass additional attributes for <li> tags. If null <li> is omitted.
   * @param $aClass additional attributes for <a> tags. If null <a> is omitted.
   * @returns html string to output
   */
  public function renderDataList($dataSetPage, $selector='', $liClass=' class="nav-item"', $aClass=' class="nav-link"') {
    $out = '';
    if (!strlen($selector)) {
      $selector = 'limit=30,sort=random';
    }
    $headwords = $dataSetPage->children($selector);
    foreach ($headwords as $headword) {
      if (!is_null($liClass)) $out .= "<li$liClass>";
      if (is_null($aClass)) {
        $out .= $t;
      } else {
        $out .= "<a href='{$headword->url}'{$aClass}>".str_replace('$', '|', $headword->title)."</a>";
      }
      if (!is_null($liClass)) $out .= '</li>';
      $out .= "\n";
    }
    return $out;
  }



/***********************************************************************
 * UTILITY FUNCTIONS
 **********************************************************************/
  /**
   * Sanitize user input
   * 
   * @param $input string
   * @returns string to use as selector or array index
   */
  public function sanitizeInput($input) {
    // TODO ?? mb_internal_encoding('UTF-8');
    // TODO ?? html_entity_decode($input);

    // filter out some illegal characters
    $ret = mb_ereg_replace('["]', '', $input);

    // replace letters with others
    foreach ($this->letterSubstitutions as $what => $with)
      $ret = mb_ereg_replace($what, $with, $ret);

    // lower case
    return mb_strtolower($ret);
  }
}
