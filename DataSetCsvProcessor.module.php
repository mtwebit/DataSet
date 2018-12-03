<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * DataSet CSV import module
 * 
 * Provides CSV import functions for the DataSet module.
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
 
class DataSetCsvProcessor extends WireData implements Module {

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
    $this->message("Counting records in {$file->name}.", Notice::debug);
    ini_set("auto_detect_line_endings", true);
    $fd = fopen($file->filename, 'rb');
    if (!$fd) {
      $this->error("Unable to open {$file->name}.");
      return false;
    }
    // count rows
    $count = 0;
    while (fgets($fd)) { // TODO large lines?
      $count++;
    }
    fclose($fd);
    // subtract the header row if exists
    if ($params['input']['header'] == 1) $count--;
    return $count;
  }


  /**
   * Process data and add/update/delete child nodes under $dataSetPage
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $file filefield entry to process
   * @param $taskData task data assoc array
   * @param $params array of config parameters like the task object, timeout, tag name of the entry etc.
   * returns false on fatal error
   */
  public function process(Page $dataSetPage, $file, &$taskData, &$params) {
    $this->message("Importing records from {$file->name}.", Notice::debug);
    ini_set("auto_detect_line_endings", true);
    $fd = fopen($file->filename, 'rb');
    if (!$fd) {
      $this->error("Unable to open {$file->name}.");
      return false;
    }

    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
    $task = $params['task'];

    // count and store a few processed records
    $newPageCounter = 0; $newPages = array();
    // Entry record number from the beginning of the input (offset)
    $entrySerial = 0;

    // skip the header row if needed
    if ($params['input']['header'] == 1) fgets($fd);

    // determine what columns are required
    if (isset($params['input']['required_fields']) && is_array($params['input']['required_fields'])) {
      $req_fields = $params['input']['required_fields'];
    } else {
      $req_fields = array();
    }

    // check if we need to skip a few records
    if ($taskData['offset'] > 0) {
      $this->message('Skipping '.$taskData['offset'].' entries.', Notice::debug);
      while (!feof($fd)) {
        fgets($fd);
        if (++$entrySerial == $taskData['offset']) break;
      }
      $taskData['offset'] = 0; // clear the old offset, will be set again later on
    }

    // set an initial milestone
    $taskData['milestone'] = $entrySerial + 20;

    while ($csv_string=fgets($fd)) {
      // check whether the task is still allowed to execute
      if (!$tasker->allowedToExecute($task, $params)) {
        $taskData['task_done'] = 0;
        $taskData['offset'] = $entrySerial;
        break; // the foreach loop
      }

      // increase the number of processed records and the actual offset counter
      // TODO are they the same?
      $taskData['records_processed']++;
      $entrySerial++;

      // read and partially process the CSV data
      if (isset($params['input']['encoding'])) {
        $csv_string = iconv($params['input']['encoding'], 'utf-8', $csv_string);
        if ($csv_string === FALSE) {
          $this->error("ERROR: invalid encoding '{$params['input']['encoding']}'.");
          break;
        }
      }

      // TODO csv input and field data will be trimmed. Provide an option for this.
      $csv_string = trim($csv_string);
      $this->message("Processing input record: {$csv_string}.", Notice::debug);

      // add a serial number to the beginning of the record
      // it will get index 0 in the $csv_data array
      // this also ensures that CSV files with only one column (and no delimiter) can be processed this way
      $csv_data = str_getcsv($entrySerial.$params['input']['delimiter'].$csv_string, $params['input']['delimiter'], $params['input']['enclosure']);

      $ptemplate = wire('templates')->get($params['pages']['template']);
      $selector = $params['pages']['selector']; // will be altered later

      // stores field data read from the input
      $field_data = array();
  
      // transfer input data to a field array
      // TODO sanitize user input
      foreach ($params['fieldmappings'] as $field => $column) {
        if (is_numeric($column)) { // a single column from the input
          if (!isset($csv_data[$column])) {
            $this->error("ERROR: column '{$column}' for field '{$field}' not found in the input. Could be a wrong delimiter or malformed input?");
            continue 2; // go to the next record in the input
          }
          $value = trim($csv_data[$column], "\"'\t\n\r\0\x0B");
        } else if (is_array($column)) { // a set of columns from the input
          $value = '';
          foreach ($column as $col) {
            if (is_string($col)) $value .= $col; // a string between column data
            else if (is_numeric($col)) { // a single column
              if (!isset($csv_data[$col])) {
                $this->error("ERROR: column '{$col}' for field '{$field}' not found in the input. Could be a wrong delimiter or malformed input?");
                continue 3; // go to the next record in the input
              }
              $value .= trim($csv_data[$col], "\"'\t\n\r\0\x0B");
            } else {
              $this->error("ERROR: invalid column specifier '{$col}' for field '{$field}'");
              break 3; // stop processing records, the error needs to be fixed
            }
          }
        } else { // the column is not an integer and not an array
          $this->error("ERROR: invalid column specifier '{$column}' for field '{$field}'");
          break 2; // stop processing records, the error needs to be fixed
        }

        // skip the field if it is empty
        if (!strlen($value)) continue;

        // store the value
        $field_data[$field] = $value;

        // if this field is used in the page selector then replace it with its value
        if (strpos($selector, '@'.$field)) {
          if (mb_strlen($field_data[$field])>100) {  // a ProcessWire constrain
            $this->warning("WARNING: the value of selector '{$field}' is too long. Truncating to 100 characters.");
          }
          // This removes [ ] and other chars, see https://github.com/processwire/processwire/blob/master/wire/core/Sanitizer.php#L1506
          // HOWTO fix this?
          $svalue = wire('sanitizer')->selectorValue($field_data[$field]);

          // TODO
          // if a field value used in the selector is missing then the selector will not work
          
          // TODO
          // rewrite the selector setting as an array of fields to be matched
          
          // page reference selectors
          $fconfig = $ptemplate->fields->get($field);
          if ($fconfig->type instanceof FieldtypePage) {
            $svalue = wire('modules')->DataSet->getReferredPage($fconfig, $field_data[$field]);
            if ($svalue === NULL) {
              $this->warning("WARNING: Referenced page {$value} for field {$field} is not found.");
              continue;
            }
          }
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
      // it will log error and warning messages
      $newPage = $this->modules->DataSet->importPage($dataSetPage, $params['pages']['template'], $selector, $field_data, $file->tags(true));
      
      if ($newPage !== NULL && $newPage instanceof Page) {
        $newPages[] = $newPage->title;
      }

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData, $params) && count($newPages)) {
        $this->message('Import successful for '.implode(', ', $newPages));
        // set the next milestone
        $taskData['milestone'] = $entrySerial + 20;
        // clear the new pages array (the have been already reported in the log)
        $newPages = array();
      }

    }

    fclose($fd);

    // print out some info for the user
    if (count($newPages)) $this->message('Import successful for '.implode(', ', $newPages));

    return true;
  }

}
