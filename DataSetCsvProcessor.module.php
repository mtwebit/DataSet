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

    // check if we need to skip a few records
    if ($taskData['offset'] > 0) {
      $this->message('Skipping '.$taskData['offset'].' entries.', Notice::debug);
      while (!feof($fd)) {
        fgets($fd);
        if (++$entrySerial == $taskData['offset']) break;
      }
      $taskData['offset'] = 0; // clear the old offset, will be set again later on
    }

    while ($csv_string=fgets($fd)) {
      // increase the number of processed records and the actual offset counter
      // TODO are they the same? als offset and records_processed seem are same?
      $taskData['records_processed']++;
      $entrySerial++;

      // read and partially process the CSV data
      // TODO csv input and field data will be trimmed. Provide an option for this.
      $csv_string = trim($csv_string);
      $this->message("Processing input record: {$csv_string}.", Notice::debug);

      // add a serial number to the beginning of the record
      // it will get index 0 in the $csv_data array
      // this also ensures that CSV files with only one column (and no delimiter) can be processed this way
      $csv_data = explode($params['input']['delimiter'], $entrySerial.$params['input']['delimiter'].$csv_string);

      // TODO sanitize user input
      // OLD version for processing the selector
      // It is buggy since %1$ refers to the 0. index element and it could not sanitize input
      // $selector = vsprintf($params['pages']['selector'], $csv_data[1..]);
      $selector = $params['pages']['selector']; // will be processed later

      // stores field data read from the input
      $field_data = array();
  
      // transfer input data to a field array
      // TODO sanitize user input
      foreach ($params['fieldmappings'] as $field => $column) {
        if (is_numeric($column)) { // a single column from the input
          if (!isset($csv_data[$column])) {
            $this->error("ERROR: column '{$column}' for field '{$field}' not found in the input. Could be a wrong delimiter or malformed input?");
            break 2; // go to the next record in the input
          }
          $field_data[$field] = trim($csv_data[$column], "\"'\t\n\r\0\x0B");
        } else if (is_array($column)) { // a set of columns from the input
          $mixvalue = '';
          foreach ($column as $col) {
            if (is_string($col)) $mixvalue .= $col; // a string between column data
            else if (is_numeric($col)) { // a single column
              if (!isset($csv_data[$col])) {
                $this->error("ERROR: column '{$col}' for field '{$field}' not found in the input. Could be a wrong delimiter or malformed input?");
                break 3; // go to the next record in the input
              }
              $mixvalue .= trim($csv_data[$column], "\"'\t\n\r\0\x0B");
            } else {
              $this->error("ERROR: invalid column specifier '{$col}' for field '{$field}'");
              break 3; // stop processing records, the error needs to be fixed
            }
          }
          $field_data[$field] = $mixvalue;
        } else { // the column is not an integer and not an array
          $this->error("ERROR: invalid column specifier '{$column}' for field '{$field}'");
          break 2; // stop processing records, the error needs to be fixed
        }
        if (strpos($selector, '@'.$field)) {
          $svalue = wire('sanitizer')->selectorValue($field_data[$field]);
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
    }

    fclose($fd);

    // print out some info for the user
    $this->message('Import successful for '.implode(', ', $newPages));

    return true;
  }

}
