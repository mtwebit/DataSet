<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * DataSet CSV import module
 * 
 * Provides CSV import functions for the DataSet module.
 * 
 * Copyright 2018-2020 Tamas Meszaros <mt+git@webit.hu>
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
    if (isset($params['input']['location'])) { // file location override
      $fileInfo = $this->modules->DataSet->getFileInfoFromURL($params['input']['location']);
      if ($fileInfo === false) return false;
    } else {
      $fileInfo = array('path' => $file->filename, 'name' => $file->name);
    }

    $this->message("Counting records in {$fileInfo['name']}.", Notice::debug);
    ini_set("auto_detect_line_endings", true);
    $fd = fopen($fileInfo['path'], 'rb');
    if (!$fd) {
      $this->error("ERROR: Unable to open {$fileInfo['name']}.");
      return false;
    }
    // count rows
    $count = 0;
    while (fgetcsv($fd, $params['input']['max_line_length'],
                          $params['input']['delimiter'],
                          $params['input']['enclosure'])) {
      $count++;
    }
    fclose($fd);
    // subtract the header row if exists
    if ($params['input']['header'] != 0) $count -= $params['input']['header'];
    return $count;
  }


  /**
   * Process data and add/update/delete child nodes under $dataSetPage
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $file filefield entry to process
   * @param $taskData task data assoc array
   * @param $params array of config parameters like the task object, timeout, template etc.
   * returns false on fatal error
   */
  public function process(Page $dataSetPage, $file, &$taskData, &$params) {
    if (isset($params['input']['location'])) { // file location override
      $fileInfo = $this->modules->DataSet->getFileInfoFromURL($params['input']['location']);
      if ($fileInfo === false) return false;
      $this->message("WARNING: using location override '{$params['input']['location']}' instead of '{$file->name}'.");
    } else {
      $fileInfo = array('path' => $file->filename, 'name' => $file->name);
    }

    $this->message("Importing records from {$fileInfo['name']}.", Notice::debug);
    ini_set("auto_detect_line_endings", true);
    $fd = fopen($fileInfo['path'], 'rb');
    if (!$fd) {
      $this->error("ERROR: unable to open {$fileInfo['name']}.");
      return false;
    }

    $ptemplate = wire('templates')->get($params['pages']['template']);
    if (!$ptemplate instanceof Template) {
      $this->error("ERROR: unknown template: {$params['pages']['template']}.");
      fclose($fd);
      return false;
    }

    // get a reference to Tasker and the task
    $tasker = $this->modules->Tasker;
    $task = $params['task'];

    // count and store a few processed records
    $newPageCounter = 0; $newPages = array();

    // set the import status to not finished
    $notEOF = true;

    // check and set encoding
    if (isset($params['input']['encoding'])) {
      if (!setlocale(LC_CTYPE, $params['input']['encoding'])) {
        $this->error("ERROR: locale {$params['input']['encoding']} is not supported by your system.");
        fclose($fd);
        return false;
      }
      $encoding = $params['input']['encoding'];
    } else {
      $encoding = 'UTF-8';
    }

    // skip header rows if needed
    if ($params['input']['header'] != 0) {
      $i = $params['input']['header'];
      while ($i-- > 0) fgets($fd);
    }

    // array of required column IDs, TODO rename to required_columns
    $required_columns = (isset($params['input']['required_fields']) ? $params['input']['required_fields'] : array());

    // array of required field names
    $required_fields = (isset($params['pages']['required_fields']) ? $params['pages']['required_fields'] : array());

    // filter out columns
    $exclude_expr = (isset($params['input']['exclude_filter']) ? 'return '.preg_replace_callback('|@(\d{1,3})|', function($matches) {
          return '$csv_data['.$matches[1].']';
        }, $params['input']['exclude_filter']).';' : 0);

    // set default values for field data
    if (isset($params['field_data_defaults']) && is_array($params['field_data_defaults'])) {
      $field_data_defaults = $params['field_data_defaults'];
    } else {
      $field_data_defaults = array();
    }

    // set default values for CSV columns
    if (isset($params['csv_data_defaults']) && is_array($params['csv_data_defaults'])) {
      $csv_data_defaults = $params['csv_data_defaults'];
    } else {
      $csv_data_defaults = array();
    }

    // check if we need to skip a few records
    if ($taskData['records_processed'] > 0) {
      $entrySerial = 0;
      while (false !== ($notEOF = fgetcsv($fd, $params['input']['max_line_length'],
                          $params['input']['delimiter'],
                          $params['input']['enclosure']))) {
        if (++$entrySerial == $taskData['records_processed']) break;
      }
      $this->message('Skipped '.$entrySerial.' entries.', Notice::debug);
    }

    // set an initial milestone
    $taskData['milestone'] = $taskData['records_processed'] + 30;

// TODO rethink return status

//
// The MAIN data import loop (if we still have data)
//
    if ($notEOF) do {
      if (!$tasker->allowedToExecute($task, $params)) {   // if we don't have time to import the data or the task has been suspended
        $taskData['task_done'] = 0;
        break; // ... the loop
      }

      // stop importing if we've reached the maximum (e.g. due to a limit)
      if (isset($params['input']['limit']) && $taskData['records_processed'] >= $params['input']['limit']) {
        break; // ... the loop
      }

      $tasker->profilerReset();
      $this->message("\n".$tasker->profilerGetTimestamp().'Reading record #'.($taskData['records_processed'] + 1).' from the input...', Notice::debug);

      // fgetcsv() ensures that new lines in fields are processed correctly
      $csv_data = fgetcsv($fd, $params['input']['max_line_length'],
                          $params['input']['delimiter'],
                          $params['input']['enclosure']);

      if ($csv_data === FALSE) {
        break; // ... the loop as there is no more data
      }

      // increase the number of processed records
      $taskData['records_processed']++;

      $record = implode($params['input']['delimiter'], $csv_data);

      // check encoding, TODO this is fairly slow, see https://stackoverflow.com/questions/1523460/ensuring-valid-utf-8-in-php
      if (!mb_check_encoding($record, $encoding)) {
        if (!mb_check_encoding($record, 'UTF-8')) {
          $record = utf8_encode($record);
        }
        $this->error('ERROR: wrong character encoding (not '.$encoding.') in '.$record);
        fclose($fd);
        return false;
      }

      if (count($csv_data) < 2 && count($params['fieldmappings']) > 1) {
        $this->error('ERROR: too few columns found. Could be a wrong delimiter or malformed input: '.$record);
        fclose($fd);
        return false;
      }

      // add a serial number to the beginning of the record
      // it will get index 0 in the $csv_data array
      // this also ensures that CSV files with only one column (and no delimiter) can be processed this way
      $csv_data = array_merge(array(0 => $taskData['records_processed']), $csv_data);

      // check exclude filter
      if ($exclude_expr && eval($exclude_expr) ) {
        $this->message('Skipping #'.$taskData['records_processed'].' due to exclude filter match: '.$exclude_expr, Notice::debug);
        continue;  // go to the next record in the input
      }

      // check for required fields
      foreach ($required_columns as $column) {
        if (!isset($csv_data[$column]) || empty($csv_data[$column])) {
          if (!isset($params['input']['silent_missing']))
            $this->error("ERROR: missing required column {$column} in the input: ".$record);
          continue 2; // go to the next record in the input
        }
      }

      $this->message($tasker->profilerGetTimestamp().'Processing input record: '.$record, Notice::debug);

      // use default values
      if (count($csv_data_defaults)) {
        // this will not replace existing but empty cells in the input
        // $csv_data = array_replace($csv_data_defaults, $csv_data);
        foreach ($csv_data_defaults as $key => $value) {
          if (!isset($csv_data[$key]) || empty($csv_data[$key])) $csv_data[$key] = $value;
        }
      }

      $this->message('Input record after defaults merged: '.$record, Notice::debug);

      $selector = $params['pages']['selector']; // will be altered later
      // TODO rework how these selectors specified.
      // Is it enough to specify field names and not field_name=@field_name

      // stores field data read from the input
      $field_data = $field_data_defaults;
  
      // transfer input data to a field array
      // TODO sanitize user input
      foreach ($params['fieldmappings'] as $field => $column) {

        if (is_numeric($column)) {
          // if the column is an integer then use a single column from the input
          if (!isset($csv_data[$column]) && !isset($field_data_defaults[$column])) {
            // if the column does not present and there is no default field value then dump an error a skip the record
            $this->error("ERROR: column '{$column}' for field '{$field}' is missing (and no default value is set) in ".$record);
            continue 2; // go to the next record in the input
          }
          $value = trim($csv_data[$column], "\"'\t\n\r\0\x0B");

        } elseif (is_array($column)) {
          // if the column is an array then
          // 1. Glue array
          //    It could be a request to merge multiple columns together
          //    In this case $column is a simple (indexed) array
          //    where elements can be column IDs and strings glued to them
          //    the final value will be composed from several columns and glue strings
          //    Examle: [ 'The page title is ', 4, '.' ]  (4 is a numeric column ID)
          // 2. Explode array
          //    It can specify column types that require special import methods
          //    In this case $column is an associative array
          //    where the 1st element is the data type and the rest are the arguments to the import
          //    valid scenarios are
          //    An array: import several values into the field
          //      { "type": "array", "separator": "|", "column": <column ID> }

// TODO is it possible to prepare these as sprintf statements once and just evaluate here?

          if (isset($column['type'])) {   // a string to explode into array elements
            switch($column['type']) {
              case 'array':
                if (isset($column['separator'])) $asep = $column['separator']; else $asep='|';
                if (!isset($column['column'])) {
                  $this->error("ERROR: '{$colum}' for field '{$field}' contains no column ID.");
                  fclose($fd);
                  return false; // stop processing records, the error needs to be fixed
                }  
                $value = explode($asep, $csv_data[$column['column']]);
                break;
              default:
                $this->error("ERROR: column type '{$column['type']}' for field '{$field}' is invalid.");
                fclose($fd);
                return false; // stop processing records, the error needs to be fixed
            }
            $this->message("Column {$column['column']} is interpreted as '{$column['type']}'.", Notice::debug);
          } else {    // glue array elements together
            $value = '';
            $need_value = false;    // check if the field uses column values from the actual input
            $has_value = false;     // and at least one of them has a value
            // if the glue array references any column from the input then we require a value for at least one column
            // if it is a static string then it is used as is
            foreach ($column as $col) {
              if (is_string($col)) {
                $value .= $col; // a glue string between column values
                // $has_value = true;
              } else if (is_numeric($col)) {  // a column ID, get its data
                $need_value = true;
                if (!isset($csv_data[$col])) {  // invalid column specified
                  $this->error("ERROR: column '{$col}' for field '{$field}' not found (and no default value has been set for that CSV column) in ".$record);
                  continue 3; // go to the next record in the input
                } elseif (strlen($csv_data[$col])) {
                  // append the column's value
                  $value .= trim($csv_data[$col], " \"'\t\n\r\0\x0B");    // TODO make this configurable?  Trim the space as well?
                  $has_value = true;
                }
              } else {
                $this->error("ERROR: invalid column specifier '{$col}' used in composing a value for field '{$field}'");
                fclose($fd);
                return false; // stop processing records, the error needs to be fixed
              }
            }
            if ($need_value && !$has_value) {  // none of the columns used in the glue string has a value
              if (!isset($params['input']['silent_missing']))
                $this->warning("WARNING: all columns values for constructing field '{$field}' are empty in ".$record);
              $value = ''; // set an empty value that later causes to skip this field and switch to the next
            }
            $value = trim($value);  // TODO make this configurable?
          }
        } else { // the column is not an integer and not an array
          $this->error("ERROR: invalid column specifier '{$column}' given for field '{$field}'.");
          fclose($fd);
          return false; // stop processing records, the error needs to be fixed
        }

        // check if the field is empty
        if ((is_array($value) && !count($value)) || (is_string($value) && !strlen($value))) {
          if (strpos($selector, '@'.$field)) {  // if this field is used in the selector
            $selector = str_replace('@'.$field, "''", $selector); // specify an empty value for this field in the selector
            $this->message("WARNING: Field {$field} presents in the Page selector but it got no value from the input {$record}.", Notice::debug);
          }
          continue; // go to the next field in the input
        }

        // store the value
        $field_data[$field] = $value;

        // if this field is used in the page selector then replace it with its value
        if (strpos($selector, '@'.$field)) {
          if (mb_strlen($field_data[$field])>100) {  // a ProcessWire constrain
            $this->warning("WARNING: the value of selector '{$field}' is too long. Truncating to 100 characters. The input was ".$record);
          }
          // TODO This removes [ ] and other chars, see https://github.com/processwire/processwire/blob/master/wire/core/Sanitizer.php#L1506
          // HOWTO fix this?
          $svalue = $this->wire('sanitizer')->selectorValue($field_data[$field]);

          // TODO
          // if a field value used in the selector is missing then the selector will not work
          
          // TODO
          // rewrite the selector setting as an array of fields to be matched
          
          // handle page reference selectors
          $fconfig = $ptemplate->fields->get($field);
          if ($fconfig == NULL) {
            $this->error("ERROR: unable to retrieve configuration for field {$field} on template {$params['pages']['template']}.");
            fclose($fd);
            return false; // stop processing records, the error needs to be fixed
          }
          if ($fconfig->type instanceof FieldtypePage) {
            $pageSelector = $this->modules->DataSet->getPageSelector($fconfig, $field_data[$field]);
            $svalue = $this->pages->get($pageSelector); // do not check for access and published status
            if ($svalue === NULL || $svalue instanceof NullPage) {
              if (in_array($field, $required_fields)) {
                $this->error("ERROR: skipping the record due to missing referenced page {$value} for field {$field} in the input ".$record);
                continue 2; // go to the next record in the input
              } else {
                $this->warning("WARNING: ignoring the field due to missing referenced page {$value} for field {$field} in the input ".$record);
                $selector = str_replace('@'.$field, "''", $selector); // specify an empty value for this field in the selector
                $this->message("WARNING: Field {$field} presents in the Page selector but it got no value from the input {$record}.", Notice::debug);
                continue;   // process the next field
              }
            }
          }
          $selector = str_replace('@'.$field, $svalue, $selector);
        }
      }

      $this->message($tasker->profilerGetTimestamp()."Data interpreted as ".str_replace("\n", " ", print_r($field_data, true)), Notice::debug);

      if (!count($field_data)) continue;    // nothing to import

      if (stripos($selector, '@')) {
        $this->error("ERROR: could not instantiate Page selector '{$selector}' from input data ".$record);
        continue;
      }

      // add the child page template to the selector
      $selector .= ',template='.$ptemplate;

      $this->message("Page selector is {$selector}.", Notice::debug);

      // create or update the page
      // it will log error and warning messages
      $newPage = $this->modules->DataSet->importPage($dataSetPage, $selector, $field_data, $params);

      if ($newPage instanceof Page) {
        $newPages[] = $newPage->title;
      } elseif ($newPage === false) {
        $this->error("ERROR: could not import the record '".$record."'");
      }

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData) && count($newPages)) {
        $this->message('Import successful for '.implode(', ', $newPages), Notice::debug);
        // set the next milestone
        $taskData['milestone'] = $taskData['records_processed'] + 30;
        // clear the new pages array (the have been already reported in the log)
        $newPages = array();
      }

      $this->message($tasker->profilerGetTimestamp().'Done processing record #'.$taskData['records_processed'], Notice::debug);

    } while (true);
//
// END of the MAIN data import loop (if we still have data)
//

    fclose($fd);

    // print out some info for the user
    if (count($newPages)) $this->message('Import successful for '.implode(', ', $newPages), Notice::debug);

    return true;
  }

}
