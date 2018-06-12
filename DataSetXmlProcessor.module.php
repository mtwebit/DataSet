<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * DataSet XML import module
 * 
 * Provides XML import functions for the DataSet module.
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
 
class DataSetXmlProcessor extends WireData implements Module {

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

  /**
   * Called only when this module is installed
   */
  public function ___install() {
  }


  /**
   * Called only when this module is uninstalled
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


  /**
   * Count XML entries in a file
   * 
   * @param $file filefield entry to process
   * @param $params assoc array of config parameters like the tag name of the entry
   * returns false on fatal error, number of records on success
   */
  public function countRecords($file, &$params) {
    // create a new XML pull parser
    $xml = new \XMLReader();

    // open the file
    if (!$xml->open($file->filename)) {
      $this->error("Unable to open {$file->name}.");
      return false;
    }

/* TODO skip validation as we don't specify a DTD atm.
    $xml->setParserProperty(\XMLReader::VALIDATE, false);
    if (!$xml->isValid()) {
      $this->module->error("Invalid XML file {$file->name}.");
      return false;
    }
*/

    // count entries
    $count = 0;
    // find the first entry
    while ($xml->read() && $xml->localName != $params['input']['delimiter']);

    // increase the counter if the first entry has been found
    if ($xml->localName == $params['input']['delimiter']) $count++;

    while ($xml->next($params['input']['delimiter'])) {
      if ($xml->nodeType != \XMLReader::ELEMENT) continue;
      $count++;
    }

    $xml->close();

    return $count;
  }


  /**
   * Import data from the XML file and add/update child nodes under $dataSetPage
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $file filefield entry to process
   * @param $taskData task data assoc array
   * @param $params array of config parameters like the task object, timeout, tag name of the entry etc.
   * returns false on fatal error
   */
  public function process(Page $dataSetPage, $file, &$taskData, &$params) {
    $this->message("Importing records from {$file->name}.", Notice::debug);

    // create a new XML pull parser
    $xml = new \XMLReader();

    // open the file
    if (!$xml->open($file->filename, 'utf-8')) {
      $this->error("Unable to open {$file->name}.");
      return false;
    }

    // properties must be set after open
    // Do not substitute entities and do not expand references
    $xml->setParserProperty(\XMLReader::SUBST_ENTITIES, false);

/* TODO skip validation as we don't specify a DTD atm.
    $xml->setParserProperty(\XMLReader::VALIDATE, false);
    if (!$xml->isValid()) {
      $this->module->error("Invalid XML file {$file->name}.");
      return false;
    }
*/

    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
    $task = $params['task'];

    // count and store a few processed records
    $newPageCounter = 0; $newPages = array();
    // Entry record number from the beginning of the input (offset)
    $entrySerial = 0;
    // set the import status to not finished
    $notFinished = true;

    // find the first entry tag
    while ($xml->read() && $xml->localName != $params['input']['delimiter']);

    // check if we need to skip a few records
    if ($taskData['offset'] > 0) {
      $this->message('Skipping '.$taskData['offset'].' entries.', Notice::debug);
      // read the next entry and alter the finished status if there is no more entries
      while ($notFinished=$xml->next($params['input']['delimiter'])) {
        // skip the end element
        if ($xml->nodeType != \XMLReader::ELEMENT) continue;
        // skip the specified number of entries
        if (++$entrySerial == $taskData['offset']) break;
      }
      $taskData['offset'] = 0; // clear the old offset, will be set again later on
    }

    if ($notFinished) do {
      // increase the actual offset counter
      $entrySerial++;

      // skip the element if it is empty
      if ($xml->isEmptyElement) continue;

      if ($xml->nodeType != \XMLReader::ELEMENT || $xml->localName != $params['input']['delimiter']) {
        // this should not happen
        $this->error("Internal XML parsing error at {$xml->localName}");
        // skip to the next <entry> tag
        continue;
      }

      // increase the record counter
      $taskData['records_processed']++;

      // read and partially process the XML data
      $xml_string = $xml->readOuterXML();
      $xml_data = new \SimpleXMLElement($xml_string);
      $xml_data->substituteEntities = false;
      $xml_data->resolveExternals = false;


      // PW selector to select matching child nodes
      $selector = $params['pages']['selector'];

      // transfer input data to a field array
      $field_data = array();
      foreach ($params['fieldmappings'] as $field => $xselect) {
        if ($xselect == '.') { // get the actual record
          $value = $xml_string;
        } else {
          $xnodes = $xml_data->xpath($xselect);
          if (!count($xnodes)) {
            $this->error("ERROR: XPath expression '{$xselect}' has no match in '{$xml_string}'. Skipping the record.");
            continue 2; // go to the next record
          }
          $value = (string) $xnodes[0]; // TODO multiple values?
        }
        // trim whitespaces from the value
        $value = trim($value, "\"'\t\n\r\0\x0B");
        // don't allow long titles -> TODO should be configurable
        if ($field=='title' && mb_strlen($value) > 50) {
          $spos = mb_strpos($value, ' ', 50);
          if ($spos < 1) $spos = 70;
          $value = mb_substr($value, 0, $spos);
        }
        $field_data[$field] = $value;
        if (strpos($selector, '@'.$field)) {
          $svalue = wire('sanitizer')->selectorValue($value);
          $selector = str_replace('@'.$field, $svalue, $selector);
        }
      }

      $this->message("Data interpreted as ".str_replace("\n", " ", print_r($field_data, true)), Notice::debug);
      $this->message("Page selector is {$selector}.", Notice::debug);

      // create or update the page
      $newPage = $this->modules->DataSet->importPage($dataSetPage, $params['pages']['template'], $selector, $field_data, $file->tags(true));

      if ($newPage instanceof Page) $newPages[] = $newPage->title;


      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData)) {
        $this->message('Import successful for '.implode(', ', $newPages));
        $newPages = array();
      }

      if (!$tasker->isActive($task)) {
        $this->message("Suspending import at offset {$entrySerial} since the import task is no longer active.", Notice::debug);
        $taskData['offset'] = $entrySerial;
        $taskData['task_done'] = 0;
        break; // the foreach loop
      }

      if ($params['timeout'] && $params['timeout'] <= time()) { // allowed execution time is over
        $this->message("Suspending import at offset {$entrySerial} since maximum execution time is over.", Notice::debug);
        $taskData['offset'] = $entrySerial;
        $taskData['task_done'] = 0;
        break;  // the while loop
      }

    } while ($xml->next($params['input']['delimiter']));

    // close the XML input
    $xml->close();

    // print out some info for the user
    $this->message('Import successful for '.implode(', ', $newPages));

    return true;
  }

}
