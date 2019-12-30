<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * DataSet module
 * 
 * Provides data set handling for ProcessWire.
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
 
class DataSet extends WireData implements Module {
  // the base URL of the module's admin page
  private $redirectUrl = '';
  const DEF_IMPORT_OPTIONS = '{
  "name": "Default import configuration",
  "comment": "any text",
  "input": {
    "type": "csv",
    "delimiter": ",",
    "max_line_length": 2048,
    "header": 1,
    "enclosure": "\""
  },
  "csv_data_defaults": null,
  "field_data_defaults": null,
  "fieldmappings": {
    "title": 1
  },
  "pages": {
    "template": "basic-page",
    "selector": "title=@title"
  }
}';
  private $myFields = array(
    'title' => array('type' => 'FieldtypePageTitle', 'Label' => 'Title'),
    'dataset_config' => array('type' => 'FieldtypeTextarea', 'Label' => 'DataSet global config'),
    'dataset_source_files' => array('type' => 'FieldtypeFile', 'Label' => 'DataSet source files')
  );
  public $tasker = NULL;
  public $taskerAdmin = NULL;


/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

  /**
   * Called only when this module is installed
   * 
   */
  public function ___install() {
   // check / add fieldgroup
    $fg = $this->fieldgroups->get('dataset-fieldgroup');
    if (!@$fg->id) {
      $fg = new Fieldgroup();
      $fg->name = 'dataset-fieldgroup';
      $fg->add($this->fields->get('title'));
      $field->tags = 'DataSet';
      $fg->save();
    }

    // check / add DataSet template
    $t = $this->templates->get('dataset');
    if (!$t) {
      $t = new Template();
      $t->name = 'dataset';
      $t->label = 'DataSet';
      $t->tags = 'DataSet';
      $t->noLang = true;
      $t->fieldgroup = $fg; // set the field group
      $t->noChildren = 1;
      $t->setIcon('fa-database');
      $t->save();
    }

    // create and add required fields
    foreach ($this->myFields as $fname => $fcdata) {
      $field = $this->fields->get($fname);
      if (!@$field->id) {
        $field = new Field();
        $field->name = $fname;
        $field->label = $fcdata['label'];
        $field->type = $this->modules->get($fcdata['type']);
	if ($fcdata['type'] == 'FieldtypeFile') {
          $field->description = 'The file\'s description field should contain import rules in YAML or JSON format.';
          // $field->required = 1;
          // $field->attr("name+id", 'myimages');
	  // $field->destinationPath = $upload_path;
	  $field->extensions = 'csv xml';
          $field->maxFiles = 0;
	  // $field->maxFilesize = 20*1024*1024; // 20 MiB
          $field->setIcon('fa-archive');
	  // TODO how to set these?
          // $field->overwrite = 1;
          // $field->file descriptions...rows = 15;
        }
        if ($fname != 'title') $field->tags = 'DataSet';
        $field->save();
      }
      if (!$fg->hasField($field)) $fg->add($field);
    }

    // save the fieldgroup
    $fg->save();

  }


  /**
   * Called only when this module is uninstalled
   * 
   */
  public function ___uninstall() {
    // Delete the automatically created template if no content present
    if (!$this->pages->count('template=dataset,include=hidden')) {
      $t = $this->templates->get('dataset');
      if ($t) {
        $this->templates->delete($t);
      }
      // TODO other templates may use the fg
      $fg = $this->fieldgroups->get('dataset-fieldgroup');
      if (@$fg->id) {
        $this->fieldgroups->delete($fg);
      }
      foreach ($this->myFields as $fname => $fcdata) {
        $field = $this->fields->get($fname);
        if ($field) {
          if ($field->numFieldgroups() > 0) continue;
          if (@$field->getTemplates()->count() > 0) continue;
          $this->fields->delete($field);
        }
      }
    }
  }


  /**
   * Initialization
   * 
   * This function attaches a hook for page save and decodes module options.
   */
  public function init() {
    if (!$this->modules->isInstalled('Tasker')) {
      $this->message('Tasker module is missing.  Install it before using Dataset module.');
      return;
    }
    $this->tasker = $this->modules->get('Tasker');

    if (!$this->modules->isInstalled('TaskerAdmin')) {
      $this->message('TaskerAdmin module is missing.  Install it before using Dataset module.');
      return;
    }
    $this->taskerAdmin = $this->modules->get('TaskerAdmin');

    // Installing conditional hooks
    // Note: PW < 3.0.62 has a bug and needs manual fix for conditional hooks:
    // https://github.com/processwire/processwire-issues/issues/261
    if (is_array($this->datasetTemplates)) foreach ($this->datasetTemplates as $t) {
      // hook to add import buttons to the input field for datasets
      $this->addHookAfter('InputfieldFile(name='.$this->sourcefield.')::renderItem', $this, 'addFileActionButtons');
      // hook to add purge button to the global dataset options
      $this->addHookAfter('InputfieldTextarea(name='.$this->configfield.')::render', $this, 'addGlobalActionButtons');
      // append Javascript functions
      $this->config->scripts->add($this->config->urls->siteModules . 'DataSet/DataSet.js');
      $this->config->styles->add($this->config->urls->siteModules . 'DataSet/DataSet.css');
      // make settings available for Javascript functions
      $this->config->js('tasker', [
        'adminUrl' => $this->taskerAdmin->adminUrl,
        'apiUrl' => $this->taskerAdmin->adminUrl . 'api/',
        'timeout' => 1000 * intval(ini_get('max_execution_time'))
      ]);
    }
  }



/***********************************************************************
 * HOOKS
 **********************************************************************/

  /**
   * Hook that adds buttons and handling functions to dataset source files
   * Note: it is called several times when the change occurs.
   */
  public function addFileActionButtons(HookEvent $event) {
    $field = $event->object;
    $pagefile = $event->arguments('pagefile');
    $id = $event->arguments('id');

    // can't perform any actions if the config is empty
    if (strlen($pagefile->description) < 3) {
      $event->return .= '<div>WARNING: DataSet config is missing. Actions are disabled.</div>';
      return;
    }

    // parse the config
    $fileConfig = $this->parseConfig($pagefile->description);
    if ($fileConfig === false) {
      $event->return .= '<div>ERROR: DataSet configuration is invalid. Actions are disabled.</div>';
      return;
    }

    // Query by name isn't the best idea
    $taskTitle = 'Import '.$fileConfig['name']." from {$pagefile->name} on page {$pagefile->page->title}";
    $tasks = $this->tasker->getTasks('title='.$taskTitle);
    if (!count($tasks)) $event->return .= '
    <div class="actions DataSetActions" id="dataset_file_'.$id.'" style="display: inline !important;">
      DataSet <i class="fa fa-angle-right"></i>
      <span style="display: inline !important;"><a onclick="DataSet(\'import\', \''.$pagefile->page->id.'\', \''.htmlentities($taskTitle).'\', \''.$pagefile->filename.'\', \''.$id.'\')">Import</a>
         this file</span>
    </div>';
    else $event->return .= '
      '.wire('modules')->get('TaskerAdmin')->renderTaskList('title='.$taskTitle, '', ' target="_blank"');
  }

  /**
   * Hook that adds buttons and handling functions to the global dataset config (if it is not empty)
   * Note: it is called several times when the change occurs.
   */
  public function addGlobalActionButtons(HookEvent $event) {
    $field = $event->object;

    if ($field->hasPage == null) return;

    // can't perform any actions if the config is empty
    if (strlen($field->value) < 3) {
      $event->return .= '<div>DataSet config is missing.</div>';
      return;
    }

    // parse the config
    $dsConfig = $this->parseConfig($field->value);
    if ($dsConfig === false) {
      $event->return .= '<div>DataSet config is invalid.</div>';
      return;
    }

    $taskTitle = "Purge dataset on page {$field->hasPage->title}";
    $tasks = $this->tasker->getTasks('title='.$taskTitle);
    if (!count($tasks)) $event->return .= '
    <div class="actions DataSetActions" id="dataset_file_all" style="display: inline !important;">
      DataSet <i class="fa fa-angle-right"></i>
      <span><a onclick="DataSet(\'purge\', \''.$field->hasPage->id.'\', \''.$taskTitle.'\', \'all files\', \'all\')">Purge</a>
        (DANGER: All child nodes with the above template will be removed!)</span>
    </div>';
    else $event->return .= '
      '.wire('modules')->get('TaskerAdmin')->renderTaskList('title='.$taskTitle, '', ' target="_blank"');
  }


/***********************************************************************
 * DATASET TASKS
 **********************************************************************/

  /**
   * Import a data set file - a Tasker task
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $taskData task data assoc array
   * @param $params runtime paramteres, e.g. timeout, dryrun, estimation and task object
   * @returns false on error, a result message on success
   * The method also alters elements of the $taskData array.
   */
  public function import($dataSetPage, &$taskData, $params) {
    // get a reference to the task
    $task = $params['task'];

    // check if we still have the file
    $file=$dataSetPage->{$this->sourcefield}->findOne('filename='.$taskData['file']);
    if ($file==NULL) {
      $this->error("ERROR: input file '".$taskData['file']."' is no longer present on Page '{$dataSetPage->title}'.");
      $this->warning("Moving task '{$task->title}' to the trash.");
      $task->trash();
      return false;
    }

    // process the file configuration stored in the description field
    $fileConfig = $this->parseConfig($file->description);
    if ($fileConfig === false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return false;
    }

    // and add it to the parameter set
    foreach ($fileConfig as $key => $value) {
      $params[$key] = $value;
    }

    $ctype = mime_content_type($file->filename);

    // select the appropriate input processor
    // They should support two methods:
    // * $proc->count($resource, $params) - returns the maximum number of processable records
    // * $proc->process($page, $resource, $taskData, $params) - process the input resource
    switch($fileConfig['input']['type']) {
    case 'xml':
      // try to validate the content type when the task starts
      if (!$taskData['records_processed'] && $ctype != 'xml') {
        $this->warning("WARNING: content type of {$fileConfig['name']} is not {$fileConfig['input']['type']} but {$ctype}. Processing anyway.");
      }
      $proc = $this->modules->getModule('DataSetXmlProcessor');
      break;
    case 'csv':
      // try to validate the content type when the task starts
      if (!$taskData['records_processed'] && !strpos($ctype, 'csv')) {
        $this->warning("WARNING: content type of {$fileConfig['name']} is not {$fileConfig['input']['type']} but {$ctype}. Processing anyway.");
      }
      $proc = $this->modules->getModule('DataSetCsvProcessor');
    // TODO case 'application/json':
    // TODO case 'application/sql':
       break;
    default:
      $this->error("ERROR: content type {$fileConfig['input']['type']} ({$ctype}) is not supported.");
      return false;
    }

    // initialize task data if this is the first invocation
    if ($taskData['records_processed'] == 0) {
      // estimate the number of processable records
      $taskData['max_records'] = $proc->countRecords($file, $params);
      $taskData['records_processed'] = 0;
      $taskData['task_done'] = 0;
    }

    if ($taskData['max_records'] == 0) { // empty file?
      $taskData['task_done'] = 1;
      $this->message('Import is done (input is empty).');
      return true;
    }

    $this->message("Processing file {$file->name}.", Notice::debug);

    // import the data set from the file using the appropriate input processor
    $ret = $proc->process($dataSetPage, $file, $taskData, $params);

    // save the progress before returning (for this time)
    $this->tasker->saveProgress($task, $taskData, false, false);

    if ($ret === false) return false;

    // check if the file has been only partially processed (e.g. due to max exec time is reached)
    if ($taskData['records_processed'] == $taskData['max_records']) {
      $taskData['task_done'] = 1;
      $this->message(basename($taskData['file']).' has been processed.');
    } elseif (isset($params['input']['limit']) && $taskData['records_processed'] == $params['input']['limit']) {
      $taskData['task_done'] = 1;
      $this->message(basename($taskData['file']).' has been partially processed due to a limit='.$params['input']['limit'].' parameter.');
    } 

    return true;
  }


  /**
   * Purge the entire data set by removing all its child nodes
   * 
   * @param $dataSetPage ProcessWire Page object (the data set)
   * @param $taskData task data assoc array
   * @param $params runtime paramteres, e.g. timeout and task object
   * @returns false on error, a result message on success
   */
  public function purge($dataSetPage, &$taskData, $params) {
    $dataSetConfig = $this->parseConfig($dataSetPage->{$this->configfield});
    if ($dataSetConfig===false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return false;
    }
    if (!isset($dataSetConfig['pages']['template'])) {
      $taskData['task_done'] = 1;
      $this->message('Nothing to purge.');
      return true;
    }

    $selector = 'parent='.$dataSetPage->id.',template='.$dataSetConfig['pages']['template'].',include=all';
    $this->message("Purging '{$dataSetPage->title}' using selector '{$selector}'.", Notice::debug);

    // calculate the task's actual size
    $tsize=$this->pages->count($selector.',check_access=0');

    // initialize task data if this is the first invocation
    if ($taskData['records_processed'] == 0) {
      // estimate the number of processable records
      $taskData['max_records'] = $tsize;
    }

    // check if we have processed all records
    if ($taskData['records_processed'] == $taskData['max_records']) {
      $taskData['task_done'] = 1;
      $this->message('Done deleting records.');
      return true;
    }

    // get a reference to the task
    $task = $params['task'];

    // store a few page names to print out
    $deleted = array();

    // set an initial milestone
    $taskData['milestone'] = $taskData['records_processed'] + 50;

    $children = $this->pages->findMany($selector.',check_access=0');

    $lazy = 10;

    foreach ($children as $child) {
      $taskData['records_processed']++;
      $deleted[] = $child->title;
//      $child->trash();  // probably not a good idea to fill the trash
      $child->delete(true); // delete children as well

      // Report progress and check for events if a milestone is reached
      if ($this->tasker->saveProgressAtMilestone($task, $taskData) && count($deleted)) {
        $this->message('Deleted pages: '.implode(', ', $deleted));
        // set a new milestone
        $taskData['milestone'] = $taskData['records_processed'] + 50;
        // clear the deleted pages array (the have been already reported in the log)
        $deleted = array();
      }

      // don't check the limits too often, deleting pages is fast
      if (--$lazy) continue;
      $lazy = 10;

      if (!$this->tasker->allowedToExecute($task, $params)) { // reached execution limits
        $taskData['task_done'] = 0;
        break;  // the while loop
      }
    } // foreach pages to delete

    if (count($deleted)) $this->message('Deleted pages: '.implode(', ', $deleted));

    if ($taskData['records_processed'] == $taskData['max_records']) {
      $taskData['task_done'] = 1;
      $this->message('Done deleting records.');
      return true;
    }

    return true;
  }


/***********************************************************************
 * CONTENT MANAGEMENT
 **********************************************************************/

  /**
   * Import a data page from a source and store its data in the specified field
   * 
   * @param $dataSetPage ProcessWire Page object (the data set)
   * @param $selector PW selector to check whether page already exists
   * @param $field_data assoc array of field name => value pairs to be set
   * @param $params array of config parameters like the task object, timeout, tag name of the entry etc.
   * 
   * @returns PW Page object that has been added/updated, false on error, NULL otherwise
   */
  public function importPage(Page $dataSetPage, $selector, $field_data, &$params) {
    // check the page selector
    if (strlen($selector)<2 || !strpos($selector, '=')) {
      $this->error("ERROR: invalid page selector '{$selector}' found in the input.");
      return false;
    }

    // check the page title
    if (!isset($field_data['title']) || strlen($field_data['title'])<1) {
      $this->error("ERROR: invalid / empty title found in '{$selector}'.");
      return false;
    }

/* TODO
    if (false !== strpos($selector, '&')) { // entities present...
      $title = html_entity_decode($title, 0,
                  isset(wire('config')->dbCharset) ? isset(wire('config')->dbCharset) : '');
    }
    // find pages already present in the data set
    $selector = 'title='.$this->sanitizer->selectorValue($title)
               .', template='.$dataSetConfig['pages']['template'].', include=all';
*/

    $this->message("Checking existing content with selector '{$selector}'.", Notice::debug);

    // TODO speed up selectors...
    $selectorOptions = array('getTotal' => false);
    $dataPage = $dataSetPage->child($selector.',check_access=0', $selectorOptions);

    if ($dataPage->id) { // found a page using the selector
      if (isset($params['pages']['merge']) || isset($params['pages']['overwrite'])) {
        return $this->updatePage($dataPage, $params['pages']['template'], $field_data, $params);
      } else {
        $this->message("WARNING: merge or overwrite not specified so not updating already existing data in '{$dataPage->title}'.");
        return NULL;
      }
    }

    if (!isset($params['pages']['skip_new'])) { // create a new page if needed
      $this->message("No content found matching the '{$selector}' selector. Trying to import the data as new...", Notice::debug);
      return $this->createPage($dataSetPage, $params['pages']['template'], $field_data, $params);
    } else {
      $this->error('WARNING: not importing '.str_replace("\n", " ", print_r($field_data, true))
            . ' into "'.$field_data['title'].'" because skip_new is specified.');
      return NULL;
    }
  }

  /**
   * Create and save a new Processwire Page and set its fields.
   * 
   * @param $parent the parent node reference
   * @param $template the template of the new page
   * @param $field_data assoc array of field name => value pairs to be set
   * @param $params array of config parameters like the task object, timeout, tag name of the entry etc.
   * 
   * @returns PW Page object that has been created, false on error, NULL otherwise
   */
  public function createPage(Page $parent, $template, $field_data, &$params) {
    if (!is_object($parent) || ($parent instanceof NullPage)) {
      $this->error("ERROR: error creating new {$template} named '{$title}' since its parent does not exists.");
      return false;
    }

    // check the page title
    if (!is_string($field_data['title']) || mb_strlen($field_data['title'])<2) {
      $this->error("ERROR: error creating page because its title is invalid.");
      return false;
    }

    // parent page needs to have an ID, get one by saving it
    if (!$parent->id) $parent->save();
    $page = $this->wire(new Page());
    if (!is_object($page)) {
      $this->error("ERROR: error creating new page named {$field_data['title']} from {$template} template.");
      return false;
    }
    $page->template = $template;
    $pt = wire('templates')->get($template);
    if (!is_object($pt)) {
      $this->error("ERROR: template '{$template}' does not exists.");
      return false;
    }

    $page->of(false); // set output formatting off

    $page->parent = $parent;
    $page->title = $field_data['title'];

    // save the core page now to enable adding files and images
    if (!$page->save()) {
      $this->error("ERROR: error saving new page '{$field_data['title']}'.");
      $page->delete();
      return false;
    }

    // get a reference to the task
    $task = $params['task'];

    // array of required field names
    $required_fields = (isset($params['pages']['required_fields']) ? $params['pages']['required_fields'] : array());

    if (count($field_data)) foreach ($field_data as $field => $value) {
      if ($field == 'title') continue; // already set

      if (!$pt->hasField($field)) {
        $this->error("ERROR: template '{$template}' has no field named '{$field}'.");
        $page->delete();  // delete the partially created page
        return false;
      }

      $this->message($this->tasker->profilerGetTimestamp()."Processing data for field '{$field}'.", Notice::debug);

      // get the field config
      $fconfig = $pt->fields->get($field);

      // set and store the field's value
      if (!$this->setFieldValue($page, $fconfig, $field, $value)) {
        // this is a fatal error if the field is required
        if (in_array($field, $required_fields)) {
          $this->error("ERROR: could not set the value of the required field '{$field}'.");
          $page->delete();  // delete the partially created page
          return false;
        } else {
          $this->message("'{$field}' is not required. Continuing...", Notice::debug);
        }
      }
    }

    $this->message("{$parent->title} / {$page->title} [ID #{$page->id}, template: {$page->template}] has been created.", Notice::debug);

    return $page;
  }



  /**
   * Update a Processwire Page and set its fields.
   * 
   * @param $page the PW page to update
   * @param $template the template of the updated page
   * @param $field_data assoc array of field name => value pairs to be set
   * @param $params array of config parameters like the task object, timeout, tag name of the entry etc.
   * 
   * @returns PW Page object that has been added/updated, false on error, NULL otherwise
   */
  public function updatePage(Page $page, $template, $field_data, &$params) {
    if (!is_object($page) || ($page instanceof NullPage)) {
      $this->error("ERROR: error updating page because it does not exists.");
      return false;
    }

    // check if there is anything to update
    if (!is_array($field_data) || !count($field_data)) return true;

    if ($page->template != $template) {
      $this->error("ERROR: error updating page because its template does not match.");
      return false;
    }

    // get a reference to the task
    $task = $params['task'];

    $pt = wire('templates')->get($template);

    $this->message("Updating page '{$page->title}'[{$page->id}]", Notice::debug);

    // array of field names to overwrite
    // TODO this may not work
    $overwrite_fields = (isset($params['pages']['overwrite']) ? $params['pages']['overwrite'] : array());
    // array of required field names
    $required_fields = (isset($params['pages']['required_fields']) ? $params['pages']['required_fields'] : array());


    if (count($field_data)) foreach ($field_data as $field => $value) {
      if (!$pt->hasField($field)) {
        $this->error("ERROR: template '{$template}' has no field named '{$field}'.");
        return false;
      }

      $this->message($this->tasker->profilerGetTimestamp()."Processing data for field '{$field}'.", Notice::debug);

      // get the field config
      $fconfig = $pt->fields->get($field);

      // set and save the field's value
      if (!$this->setFieldValue($page, $fconfig, $field, $value, in_array($field, $overwrite_fields))) {
        // this is a fatal error if the field is required
        if (in_array($field, $required_fields)) {
          $this->error("ERROR: could not set the value of a required field '{$field}'.");
          // TODO rollback to the page's old state?
          return false;
        } else {
          $this->message("'{$field}' is not required. Continuing...", Notice::debug);
        }
      }
    }

    $this->message("{$page->title} [ID #{$page->id}, template: {$page->template}] has been updated.", Notice::debug);

    return $page;
  }


/***********************************************************************
 * UTILITY METHODS
 **********************************************************************/
  /**
   * Load and return data set or file configuration.
   * 
   * TODO the func assumes than json_decode() exists.
   * 
   * @param $yconfig configuration in YAML form
   * @returns configuration as associative array or false on error
   */
  public function parseConfig($yconfig) {
    // load the default configuration
    $ret = json_decode(self::DEF_IMPORT_OPTIONS, true /*assoc*/);
    $valid_sections = array_keys($ret);

    $yconfig = trim($yconfig);
    // return default values if the config is empty
    if (strlen($yconfig)==0) return $ret;

    if (strpos(' '.$yconfig, 'JSON') == 1) { // JSON config format
      $config = json_decode(substr($yconfig, 4), true /*assoc*/);
      if (is_null($config)) {
        $this->error('Invalid JSON configuration: ' . json_last_error_msg());
        return false;
      }
    } else {  // YAML config format
      if (!function_exists('yaml_parse')) {
        $this->error('YAML is not supported on your system. Try to use JSON for configuration.');
        return false;
      }
      // disable decoding PHP code
      ini_set('yaml.decode_php', 0);

      // YAML warnings do not cause exceptions
      // Convert them to exceptions using a custom error handler
      set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
      }, E_WARNING);
      try {
        $config = yaml_parse($yconfig);
      } catch (\Exception $e) {
        $this->message($e->getMessage());
        restore_error_handler();
        return false;
      }

      restore_error_handler();
    }

    if (!is_array($config)) {
      return false;
    }

    // iterate over the main settings and replace default values
    foreach ($config as $section => $values) {
      if (!in_array($section, $valid_sections)) {
        $this->error("Invalid configuration section '{$section}' found in '{$yconfig}'.");
        return false;
      }
      if (is_array($values)) foreach ($values as $setting => $value) {
        if (is_array($value)) {
          // TODO validate arrays
          $ret[$section][$setting] = $value;
        } elseif (is_numeric($value)) {
          $ret[$section][$setting] = $value;
        } else {
          $ret[$section][$setting] = $this->wire('sanitizer')->text($value);
        }
      } else {
        $ret[$section] = $this->wire('sanitizer')->text($values);
      }
    }

    // $this->message("DataSet config '{$yconfig}' was interpreted as ".var_export($ret, true).'.', Notice::debug);

    return $ret;
  }

  /**
   * Build a page reference selector based on field configuration data and a search value
   * 
   * @param $fconfig field configuration
   * @param $value field value to search for
   * @returns a page object or NULL if none found
   */
  public function getPageSelector($fconfig, $value) {
    $selectors = array();
    if ($fconfig->findPagesSelector) $selectors[] = $fconfig->findPagesSelector;
    if ($fconfig->template_id) $selectors[] = "templates_id={$fconfig->template_id}";
    if ($fconfig->searchFields) {
      $sfields = '';
      foreach (explode(' ', $fconfig->searchFields) as $name) {
        $name = $this->wire('sanitizer')->fieldName($name);
        if ($name) $sfields .= ($sfields ? '|' : '') . $name;
      }
      $value = $this->wire('sanitizer')->selectorValue($value);
      if ($sfields) $selectors[] = $sfields."=".$value;
    }
    if ($fconfig->parent_id) {
      if (empty($selectors)) $selectors[] = "parent_id={$fconfig->parent_id}";
      else $selectors[] = "has_parent={$fconfig->parent_id}";
    }
    return implode(', ', $selectors);
  }

  /**
   * Get a page reference selector based on the field's configuration
   * 
   * @param $fconfig field configuration
   * @param $value search value
   * @returns configuration as associative array or false on error
   */
  public function getOptionsFieldValue($fconfig, $value) {
    $all_options = $fconfig->type->getOptions($fconfig);
    $option_id = $all_options->get('value|title='.$value);  // TODO: first for value then for title?
    if ($option_id != NULL) {
      return $option_id;
    } else {  // option not found by value or title, it must be an ID
      return $value;
    }
  }


  /**
   * Set field values (also handle updates and existing values)
   * 
   * @param $page PW Page that holds the field
   * @param $fconfig field configuration
   * @param $field field name
   * @param $value field value to set
   * @param $overwrite overwrite already existing values?
   * @returns an array of fields that need to be set after the page is saved (e.g. file and image fields)
   */
  public function setFieldValue($page, $fconfig, $field, $value, $overwrite = false) {
    // the the value is an array, store each member value separately in the field
    if (is_array($value)) {
      foreach ($value as $v) {
        if (!$this->setFieldValue($page, $fconfig, $field, $v, $overwrite)) return false;
      }
      return true;
    }

    if ($fconfig->type instanceof FieldtypePage) {    // Page reference
      $selector = $this->getPageSelector($fconfig, $value);
      $this->message($this->tasker->profilerGetTimestamp()."Page selector @ field {$field}: {$selector}.", Notice::debug);
      $refpage = $this->pages->get($selector);  // do not check for access and published status
      if ($refpage->id) {
        $this->message($this->tasker->profilerGetTimestamp()."Found referenced page '{$refpage->title}' for field '{$field}' using the selector '{$selector}'.", Notice::debug);
        $value = $refpage->id;
        $hasValue = ($page->$field ? $page->$field->has($selector) : false);
        if ($hasValue) $this->message("Field '{$field}' already has a reference to '{$refpage->title}' [{$refpage->id}].", Notice::debug);
      } else {
        $this->error("WARNING: referenced page with value '{$value}' not found for field '{$field}' using selector '{$selector}'.");
        return false;
      }
    } elseif ($fconfig->type instanceof FieldtypeFile
        || $fconfig->type instanceof FieldtypeImage) {    // Images and files
      // check whether we have the same file or the field may only contain a single value
      $this->message('Checking file/image field: maxfiles = '.$fconfig->get('maxFiles').', filecount = '.$page->$field->count(), Notice::debug);
      $hasValue = $page->$field->has($value) || ($page->$field && ($fconfig->get('maxFiles') == 1) && ($page->$field->count() > 0));
    } elseif ($fconfig->type instanceof FieldtypeOptions) {   // Numeric options
      // if the value is numeric we can't use it as a field value on options fields
      // See https://processwire.com/api/modules/select-options-fieldtype/#manipulating-options-on-a-page-from-the-api
      // So... replace $value with an option ID if possible
      $value = $this->getOptionsFieldValue($fconfig, $value);
      $hasValue = ($page->$field ? $page->$field->has($value) : false);
    } else {
      $hasValue = $page->$field ? true : false;
      // handle datetime formats
      if ($fconfig->type instanceof FieldtypeDatetime && is_string($value)) {
        $this->message("Converting '{$value}' to timestamp.", Notice::debug);
        if (false === \DateTime::createFromFormat('Y-m-d', $value)) {
          $this->error("WARNING: field '{$field}' contains invalid datatime value '{$value}'.");
          return false;
        }
        // TODO acquire the proper format from the field config
        $value = $this->datetime->stringToTimestamp($value, 'Y-m-d');
      }
    }

// TODO multi-language support for certain fields?
//  $page->$field->setLanguageValue($lang, $value);

    if ($overwrite) {
      $this->message("Overwriting field '{$field}''s old value with '{$value}'.", Notice::debug);
      $page->$field = $value;
      return $this->saveField($page, $field, $value);
    }
    if (!$hasValue) {
      if ($page->$field && $page->$field instanceof WireArray && $page->$field->count()) {
        $this->message("Adding new value '{$value}' to field '{$field}'.", Notice::debug);
        $page->$field->add($value);
        return $this->saveField($page, $field, $value);
      }
      $this->message("Setting field '{$field}' = '{$value}'.", Notice::debug);
      $page->$field = $value;
      return $this->saveField($page, $field, $value);
    }
    $this->message("WARNING: not updating already populated field '{$field}'.", Notice::debug);
    return false;
  }


  /**
   * Store a field value
   * 
   * @param $page PW Page that holds the field
   * @param $field field name
   * @param $value field value to set
   * @returns true on success, false on failure
   */
  public function saveField($page, $fieldname, $value) {
    try {
      if (!$page->save($fieldname)) {
        $this->error("ERROR: could not set field '{$fieldname}' = '{$value}' on page '{$page->title}'.");
        return false;
      }
      // Notice: sometimes save() will return true but the field won't be stored correctly
      // (e.g. an SQL error happens).
      // $config->allowExceptions = true is needed to detect this kind of errors.
      // Tasker will enforce this setting but other callers may not.
    } catch (\Exception $e) {
      $this->error("ERROR: got an exception while setting field '{$fieldname}' = '{$value}' on page '{$page->title}': ".$e->getMessage().'.');
      return false;
    }
    return true;
  }

  /**
   * Validate an URL and return filename and location info.
   * It supports local PW file locations as wire://pagenum/filename.
   * 
   * @param $uri an URI poiting to a file
   * returns array of (full_path_of_the_file, short_name_of_the_file)
   */
  public function getFileInfoFromURL($url) {
    $url = filter_var(trim($url), FILTER_VALIDATE_URL);
    if ($url === false) {
      $this->error("ERROR: invalid file location {$url}.");
      return false;
    }
    $urlparts = parse_url($url);
    $fileProto = $urlparts['scheme'];
    $fileName = basename($urlparts['path']);
    if ($fileName == '') {
      $this->error("ERROR: empty file location {$url}.");
      return false;
    }
    if ($fileProto == 'wire') {
      $pageId = $urlparts['host'];
      $page = $this->pages->get($pageId);
      if (!$page->id) {
        $this->error("ERROR: could not find page {$pageId} for file location {$url}.");
        return false;
      }
      $file = $page->{$this->sourcefield}->findOne('name='.$fileName);
      if ($file==NULL) {
        $this->error("ERROR: could not find {$fileName} on page {$page->title}.");
        return false;
      }
      $filePathName = $file->filename;
      // $fileName = $file->name; // should be the same
    } else {
      $filePathName = $url;
    }
    return array('path' => $filePathName, 'name' => $fileName);
  }
}
