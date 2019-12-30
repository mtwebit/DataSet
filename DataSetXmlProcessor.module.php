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
   * @param $params array of config parameters like the task object, timeout, tag name of the entry, template etc.
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
    $tasker = $this->modules->Tasker;
    $task = $params['task'];

    // count and store a few processed records
    $newPageCounter = 0; $newPages = array();

    // set the import status to not finished
    $notEOF = true;

    // determine what columns are required
    if (isset($params['input']['required_fields']) && is_array($params['input']['required_fields'])) {
      $req_fields = $params['input']['required_fields'];
    } else {
      $req_fields = array();
    }

    // set default values for field data
    if (isset($params['field_data_defaults']) && is_array($params['field_data_defaults'])) {
      $field_data_defaults = $params['field_data_defaults'];
    } else {
      $field_data_defaults = array();
    }

    // find the first entry tag
    while ($xml->read() && $xml->localName != $params['input']['delimiter']);

    // check if we need to skip a few records
    if ($taskData['offset'] > 0) {
      $entrySerial = 0;
      while (false !== ($notEOF = $xml->next($params['input']['delimiter']))) {
        // skip the end element
        if ($xml->nodeType != \XMLReader::ELEMENT) continue;
        // skip the specified number of entries
        if (++$entrySerial == $taskData['offset']) break;
      }
      $this->message('Skipped '.$entrySerial.' entries.', Notice::debug);
      $taskData['offset'] = 0; // clear the old offset, will be set again later on
    }

    // set an initial milestone
    $taskData['milestone'] = $entrySerial + 20;

//
// The MAIN data import loop
//
    if ($notEOF) do {
      if (!$tasker->allowedToExecute($task, $params)) {
        $taskData['offset'] = $entrySerial;
        $taskData['task_done'] = 0;
        break; // the do loop
      }

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

      // stop importing if we've reached the maximum (e.g. due to a limit)
      if (isset($params['input']['limit']) && $taskData['records_processed'] >= $params['input']['limit']) {
        break; // ... the foreach loop if there is a limit
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
      $field_data = $field_data_defaults;

      foreach ($params['fieldmappings'] as $field => $xselect) {
        if ($xselect == '.') { // get the actual record
          $value = $xml_string;
        } else {
          $xnodes = $xml_data->xpath($xselect);
          if (!count($xnodes)) {
            $this->error("ERROR: XPath expression '{$xselect}' has no match in '".htmlentities($xml_string)."'. Skipping the record.");
            continue 2; // go to the next record
          }
          $value = (string) $xnodes[0]; // TODO multiple values?
        }
        // trim whitespaces from the value
        $value = trim($value, "\"'\t\n\r\0\x0B");

        // skip the field if it is empty
        if (!strlen($value)) continue;

        // don't allow long titles -> TODO should be configurable, currently set to the PW selector strlen limit
        if ($field=='title' && mb_strlen($value) > 100) {
          $spos = mb_strpos($value, ' ', 50);
          if ($spos < 1) $spos = 70;
          $value = mb_substr($value, 0, $spos);
        }
        $field_data[$field] = $value;
        if (strpos($selector, '@'.$field)) {
          if (mb_strlen($field_data[$field])>100) {  // a ProcessWire constrain
            $this->warning("WARNING: the value of selector '{$field}' is too long. Truncating to 100 characters.");
          }
          $svalue = wire('sanitizer')->selectorValue($value);
          $selector = str_replace('@'.$field, $svalue, $selector);
        }
      }

      // check for required fields
      $not_present=array_diff($req_fields, $field_data);
      if (count($not_present)) {
        foreach ($not_present as $field) {
          $this->error("ERROR: missing value for required field '{$field}' in the input.");
        }
        $this->message(var_export($req_fields, true));
        $this->message(var_export($not_present, true));
        break;
        // continue; // go to the next record in the input
      }

      $this->message("Data interpreted as ".str_replace("\n", " ", print_r($field_data, true)), Notice::debug);
      $this->message("Page selector is {$selector}.", Notice::debug);

      // create or update the page
      $newPage = $this->modules->DataSet->importPage($dataSetPage, $selector, $field_data, $params);

      if ($newPage instanceof Page) {
        $newPages[] = $newPage->title;
      } elseif ($newPage === false) {
        $this->error("ERROR: could not import the record '".htmlentities($xml_string)."'");
      }

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData)) {
        $this->message('Import successful for '.implode(', ', $newPages));
        // set the next milestone
        $taskData['milestone'] = $entrySerial + 20;
        // clear the new pages array (the have been already reported in the log)
        $newPages = array();
      }

    } while ($xml->next($params['input']['delimiter']));
//
// END of the MAIN data import loop (if we still have data)
//

    // close the XML input
    $xml->close();

    // print out some info for the user
    $this->message('Import successful for '.implode(', ', $newPages));

    return true;
  }

}
