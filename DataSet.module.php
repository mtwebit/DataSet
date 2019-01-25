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
  public $adminUrl; //  = wire('config')->urls->admin.'page/datasets/'
  private $redirectUrl = '';
  // file tags TODO remove them
  const TAG_IMPORT='import';  // import data from sources
  const TAG_MERGE='merge';    // merge new data with already existing data
  const TAG_OVERWRITE='overwrite'; // merge and overwrite already existing data with new imports
  const TAG_DELETE='delete';  // delete data found in the source
  const TAG_PURGE='purge';    // purge the data set before import
  const DEF_IMPORT_OPTIONS = '
name: Default import configuration
comment: any text
input:
  type: csv
  delimiter: \',\'
  max_line_length: 2048
  header: 1
  enclosure: \'"\'
csv_data_defaults:
field_data_defaults:
fieldmappings:
  title: 1
pages:
  template: basic-page
  selector: \'title=@title\'
  ';

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

  /**
   * Called only when this module is installed
   * 
   */
  public function ___install() {
  }


  /**
   * Called only when this module is uninstalled
   * 
   */
  public function ___uninstall() {
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

    // Installing conditional hooks
    // Note: PW < 3.0.62 has a bug and needs manual fix for conditional hooks:
    // https://github.com/processwire/processwire-issues/issues/261
    if (is_array($this->dataset_templates)) foreach ($this->dataset_templates as $t) {

// TODO These are old methods, remove them  
      // hook after page save to import dataset entries
      $this->addHookAfter('Page(template='.$t.')::changed('.$this->sourcefield.')', $this, 'handleSourceChange');
      // hook to check global configuration changes on dataset pages
      $this->addHookAfter('Page(template='.$t.')::changed('.$this->configfield.')', $this, 'validateConfigChange');

// TODO Keep the following new methods
      // hook to add import buttons to the input field for datasets
      $this->addHookAfter('InputfieldFile(name='.$this->sourcefield.')::renderItem', $this, 'addFileActionButtons');
      // hook to add purge button to the global dataset options
      $this->addHookAfter('InputfieldTextarea(name='.$this->configfield.')::render', $this, 'addGlobalActionButtons');
      // append Javascript functions
      $this->config->scripts->add($this->config->urls->siteModules . 'DataSet/DataSet.js');
      $this->config->styles->add($this->config->urls->siteModules . 'DataSet/DataSet.css');

      $taskerAdmin = wire('modules')->get('TaskerAdmin');
      $this->adminUrl = $taskerAdmin->adminUrl;
      // make this available for Javascript functions
      $this->config->js('tasker', [
        'adminUrl' => $this->adminUrl,
        'apiUrl' => $this->adminUrl . 'api/',
        'timeout' => 1000 * intval(ini_get('max_execution_time'))
      ]);
    }
  }



/***********************************************************************
 * HOOKS
 **********************************************************************/

  /** TODO remove
   * Hook that creates a task to process the sources
   * Note: it is called several times when the change occurs.
   */
  public function handleSourceChange(HookEvent $event) {
    // return when we could not detect a real change
    if (! $event->arguments(1) instanceOf Pagefiles) return;
    $dataSetPage = $event->object;
    // create the necessary tasks and add them to the page after it is saved.
    $event->addHookAfter("Pages::saved($dataSetPage)",
      function($event) use($dataSetPage) {
        $this->createTasksOnPageSave($dataSetPage);
        $event->removeHook(null);
      });
  }

  /** TODO remove
   * Hook that validates configuration changes
   */
  public function validateConfigChange(HookEvent $event) {
    $field = $event->object;
    if (!is_object($field)) return;

    if ($field->name != $this->configfield) return;

    $page = $this->modules->ProcessPageEdit->getPage();

    $field->message("Field '{$field->name}' changed on '{$page->title}'.", Notice::debug);

    if (strlen($field->value)<3) return;

    $dataSetConfig = $this->parseConfig($field->value);
    if (false === $dataSetConfig) {
      $field->error('Invalid data set confguration.');
      $field->message($field->value.' interpreted as '.print_r($dataSetConfig, true), Notice::debug);
      return;
    }

    $field->message('Data set configuration seems to be OK.');
    $field->message('Config: '.print_r($dataSetConfig, true), Notice::debug);
  }


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
      $event->return .= '<div>DataSet config is missing.</div>';
      return;
    }

    // parse the config
    $fileConfig = $this->parseConfig($pagefile->description);
    if (!$fileConfig) {
      $event->return .= '<div>DataSet config is invalid.</div>';
      return;
    }

    $tasker = wire('modules')->get('Tasker');
    $taskTitle = 'Import '.$fileConfig['name']." from {$pagefile->name} on page {$pagefile->page->title}";
    $tasks = $tasker->getTasks('title='.$taskTitle);
    if (!count($tasks)) $event->return .= '
    <ul class="actions DataSetActions" id="dataset_file_'.$id.'" style="display: inline !important;">DataSet
      <li style="display: inline !important;">
        <span><a onclick="DataSet(\'import\', \''.$pagefile->page->id.'\', \''.htmlentities($taskTitle).'\', \''.$pagefile->filename.'\', \''.$id.'\')">Import</a>
         this file</span>
      </li>
      <div></div>
    </ul>';
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
    if (!$dsConfig) {
      $event->return .= '<div>DataSet config is invalid.</div>';
      return;
    }

    $tasker = wire('modules')->get('Tasker');
    $taskTitle = "Purge dataset on page {$field->hasPage->title}";
    $tasks = $tasker->getTasks('title='.$taskTitle);
    if (!count($tasks)) $event->return .= '
    <ul class="actions DataSetActions" id="dataset_file_all" style="display: inline !important;">DataSet
      <li style="display: inline !important;">
        <span><a onclick="DataSet(\'purge\', \''.$field->hasPage->id.'\', \''.$taskTitle.'\', \'all files\', \'all\')">Purge</a>
        (DANGER: All child nodes with the above template will be removed!)</span>
      </li>
      <div></div>
    </ul>';
    else $event->return .= '
      '.wire('modules')->get('TaskerAdmin')->renderTaskList('title='.$taskTitle, '', ' target="_blank"');
  }


/***********************************************************************
 * TASK MANAGEMENT
 **********************************************************************/

  /** TODO remove
   * Create necessary tasks when the page is ready to be saved
   * 
   * @param $dataSetPage ProcessWire Page object
   */
  public function createTasksOnPageSave($dataSetPage) {
    // check if any file needs to be handled
    $files = $dataSetPage->{$this->sourcefield}->find('tags*='.self::TAG_IMPORT.'|'.self::TAG_MERGE.'|'.self::TAG_OVERWRITE.'|'.self::TAG_PURGE.'|'.self::TAG_DELETE);
    if ($files->count()==0) return;

    $this->message('Data set source has changed. Creating background jobs to check the changes.', Notice::debug);

    // constructing tasks
    // these could be long running progs so we can't execute them right now
    // Tasker module is here to help
    $tasker = $this->modules->get('Tasker');

    $firstTask = $prevTask = NULL;
    $data = array(); // task data

    // if purge was requested on any file then purge the data set before any import occurs
    foreach ($files as $file) if ($file->hasTag(self::TAG_PURGE)) {
      $purgeTask = $tasker->createTask(__CLASS__, 'purge', $dataSetPage, 'Purge the data set', $data);
      if ($purgeTask == NULL) return; // tasker failed to add a task
      $data['dep'] = $purgeTask->id; // add a dependency to import tasks: first delete old entries
      $firstTask = $prevTask = $purgeTask;
      $this->message("Created a task to purge data set before import.", Notice::debug);
    }

    // create an import task for each input file
    foreach ($files as $name => $file) {
      $data['file'] = $name;
      $title = 'Process data found in '.$name;
      if (!$file->hasTag(self::TAG_IMPORT.'|'.self::TAG_MERGE.'|'.self::TAG_OVERWRITE.'|'.self::TAG_DELETE)) {
        continue; // no import, no update, no delete - skip this file
      }
      $task = $tasker->createTask(__CLASS__, 'import', $dataSetPage, 'Process data from '.$name, $data);
      if ($task == NULL) return; // tasker failed to add a task
      // add this task as a follow-up to the previous task
      if ($prevTask != NULL) $tasker->addNextTask($prevTask, $task);
      $prevTask = $task;
      if ($firstTask == NULL) $firstTask = $task;
    }

    $tasker->activateTask($firstTask); // activate the first task

    // if TaskedAdmin is installed, redirect to its admin page for task execution
    if ($this->modules->isInstalled('TaskerAdmin')) {
      $taskerAdmin = $this->modules->get('TaskerAdmin');
      $this->redirectUrl = $taskerAdmin->adminUrl.'?id='.$firstTask->id.'&cmd=run';
      // add a temporary hook to redirect to TaskerAdmin's monitoring page after saving the current page
      $this->pages->addHookBefore('ProcessPageEdit::processSaveRedirect', $this, 'runDataSetTasks');
    }

    return;
  }

  /** TODO remove
   * Hook that redirects the user to the tasker admin page
   */
  public function runDataSetTasks(HookEvent $event) {
    if ($this->redirectUrl != '') {
      // redirect on page save
      $event->arguments = array($this->redirectUrl);
      $this->redirectUrl = '';
    }
    // redirect is done or not needed, remove this hook
    $event->removeHook(null);
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
    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
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
    $tasker->saveProgress($task, $taskData);

    if ($ret === false) return false;

    // check if the file has been only partially processed (e.g. due to max exec time is reached)
    if ($taskData['records_processed'] == $taskData['max_records']) {
      $taskData['task_done'] = 1;
      $this->message($taskData['file'].' has been processed.');
    } elseif (isset($params['input']['limit']) && $taskData['records_processed'] == $params['input']['limit']) {
      $taskData['task_done'] = 1;
      $this->message($taskData['file'].' has been partially processed due to a limit='.$params['input']['limit'].' parameter.');
    } 

    return true;
  }


  /** TODO old version, remove
   * Import a data set - a Tasker task
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $taskData task data assoc array
   * @param $params runtime paramteres, e.g. timeout, dryrun, estimation and task object
   * @returns false on error, a result message on success
   * The method also alters elements of the $taskData array.
   */
  public function importOLD($dataSetPage, &$taskData, $params) {
    $dataSetConfig = $this->parseConfig($dataSetPage->{$this->configfield});
    if ($dataSetConfig===false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return false;
    }

    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
    $task = $params['task'];

    // check if we still have the file and its tag
    $file=$dataSetPage->{$this->sourcefield}->findOne('name='.$taskData['file'].',tags*='.self::TAG_IMPORT.'|'.self::TAG_MERGE.'|'.self::TAG_OVERWRITE.'|'.self::TAG_DELETE);
    if ($file==NULL) {
      $this->error("ERROR: input file '".$taskData['file']."' is not present or it has no IMPORT tags on Page '{$dataSetPage->title}'.");
      $this->warning("Moving task '{$task->title}' to the trash.");
      $task->trash();
      return false;
    }

    // process the file configuration stored in the description field
    $fileConfig = $this->parseConfig($file->description);
    // and add it to the parameter set
    $params['input'] = $fileConfig['input'];
    $params['pages'] = $fileConfig['pages'];
    $params['fieldmappings'] = $fileConfig['fieldmappings'];

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
      $taskData['offset'] = 0;    // file offset
    }

    if ($taskData['max_records'] == 0) { // empty file?
      $taskData['task_done'] = 1;
      $this->message('Import is done (input is empty).');
      return true;
    }

    $this->message("Processing file {$file->name}.", Notice::debug);

    // import the data set from the file using the appropriate input processor
    $ret = $proc->process($dataSetPage, $file, $taskData, $params);
    if ($ret === false) return false;

    // check if the file has been only partially processed (e.g. due to max exec time is reached)
    if ($taskData['records_processed'] == $taskData['max_records']) {
      $taskData['task_done'] = 1;
      $this->message($taskData['file'].' has been processed.');
    } elseif (isset($params['input']['limit']) && $taskData['records_processed'] == $params['input']['limit']) {
      $taskData['task_done'] = 1;
      $this->message($taskData['file'].' has been partially processed due to a limit='.$params['input']['limit'].' parameter.');
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
    $tsize=$this->pages->count($selector);

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

    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
    $task = $params['task'];

    // store a few page names to print out
    $deleted = array();

    // set an initial milestone
    $taskData['milestone'] = $taskData['records_processed'] + 50;

    $children = $this->pages->findMany($selector);

    $lazy = 10;

    foreach ($children as $child) {
      $taskData['records_processed']++;
      $deleted[] = $child->title;
//      $child->trash();  // probably not a good idea to fill the trash
      $child->delete(true); // delete children as well

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData) && count($deleted)) {
        $this->message('Deleted pages: '.implode(', ', $deleted));
        // set a new milestone
        $taskData['milestone'] = $taskData['records_processed'] + 50;
        // clear the deleted pages array (the have been already reported in the log)
        $deleted = array();
      }

      // don't check the limits too often, deleting pages is fast
      if (--$lazy) continue;
      $lazy = 10;

      if (!$tasker->allowedToExecute($task, $params)) { // reached execution limits
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
    $dataPage = $dataSetPage->child($selector, $selectorOptions);

    if ($dataPage->id) { // found a page using the selector
      if (isset($params['pages']['merge']) || isset($params['pages']['overwrite'])) {
        return $this->updatePage($dataPage, $params['pages']['template'], $field_data, isset($params['pages']['overwrite']));
      } else {
        $this->message("WARNING: merge or overwrite not specified so not updating already existing data in '{$dataPage->title}'.");
        return NULL;
      }
    }

    $this->message("WARNING: no content found matching the '{$selector}' selector. Trying to import the data...", Notice::debug);

    if (!isset($params['pages']['skip_new'])) { // create a new page if needed
      return $this->createPage($dataSetPage, $params['pages']['template'], $field_data['title'], $field_data);
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
   * @param $title title for the new page  // TODO this can be removed
   * @param $fields assoc array of field name => value pairs to be set
   * 
   * @returns PW Page object that has been created, false on error, NULL otherwise
   */
  public function createPage(Page $parent, $template, $title, $field_data = array()) {
    if (!is_object($parent) || ($parent instanceof NullPage)) {
      $this->error("ERROR: error creating new {$template} named '{$title}' since its parent does not exists.");
      return false;
    }
    if (!is_string($title) || mb_strlen($title)<2) {
      $this->error("ERROR: error creating new {$template} named '{$title}' since its title is invalid.");
      return false;
    }
    // parent page needs to have an ID, get one by saving it
    if (!$parent->id) $parent->save();
    $p = $this->wire(new Page());
    if (!is_object($p)) {
      $this->error("ERROR: error creating new page named {$title} from {$template} template.");
      return false;
    }
    $p->template = $template;
    $pt = wire('templates')->get($template);
    if (!is_object($pt)) {
      $this->error("ERROR: template '{$template}' does not exists.");
      return false;
    }

    // TODO do we need this here?
    $p->of(false); // set output formatting off

    $p->parent = $parent;
    $p->title = $title;

    // save the core page now to enable adding files and images
    if (!$p->save()) {
      $this->error("ERROR: error saving new page '{$title}'.");
      $p->delete();
      return false;
    }

    if (count($field_data)) foreach ($field_data as $field => $fdata) {
      if ($field == 'title') continue;
      if (!$pt->hasField($field)) {
        $this->error("ERROR: template '{$template}' has no field named '{$field}'.");
        $p->delete();
        return false;
      }

      // get the field config
      $fconfig = $pt->fields->get($field);

      // if we got an array of values then process all of them
      if (is_array($fdata)) foreach ($fdata as $value) {
        $ret = $this->setFieldValue($p, $fconfig, $field, $value);
      } else {
        $ret = $this->setFieldValue($p, $fconfig, $field, $fdata);
      }
      if ($ret === false) {
        $p->delete();
        return false;
      }
    }

// TODO multi-language support for pages?
/*
    //foreach ($this->languages as $lang) {
    $langs = $p->getLanguages();
    if (count($langs)) foreach ($p->getLanguages() as $lang) {
      $p->title->setLanguageValue($lang, $title);
    } else $p->title = $title;

    if (count($fields)) foreach ($fields as $field => $value) {
      // if ($p->hasField($field)) $p->$field = $value;
      if (count($langs)) foreach ($p->getLanguages() as $lang) {
        $p->{$field}->setLanguageValue($lang, $value);
      } else $p->set($field, $value);
    }
*/

    try {
      // pages must be saved now to add external resources to fields
      if (!$p->save()) {
        $this->error("ERROR: error saving new page '{$title}'.");
        $p->delete();
        return false;
      }
      // Notice: sometimes save() will return true (and the page will be saved)
      // but some fields won't be stored correctly (e.g. an SQL error happens).
      // $config->allowExceptions = true is needed to detect this kind of errors.
      // Tasker will enforce this setting but other methods may not.
    } catch (\Exception $e) {
      // Delete the partially saved page.
      $p->delete();
      // TODO very long page titles may cause problems while saving the page
      // "Unable to generate unique name for page 0"
      $this->error("ERROR: failed to create page '{$title}'.");
      $this->message($e->getMessage());
      return false;
    }
    // $this->message("{$parent->title} / {$title} [{$template}] created.", Notice::debug);

    return $p;
  }



  /**
   * Update a Processwire Page and set its fields.
   * 
   * @param $page the parent node reference
   * @param $template the template of the updated page
   * @param $field_data assoc array of field name => value pairs to be set
   * @param $overwrite - overwrite existing data?
   * 
   * @returns PW Page object that has been added/updated, false on error, NULL otherwise
   */
  public function updatePage(Page $page, $template, $field_data = array(), $overwrite = false) {
    if (!is_object($page) || ($page instanceof NullPage)) {
      $this->error("ERROR: error updating page because it does not exists.");
      return false;
    }

    // check if there is anything to update
    if (!is_array($field_data) || !count($field_data)) return true;

    // check the page title
    if (!is_string($field_data['title']) || mb_strlen($field_data['title'])<2) {
      $this->error("ERROR: error updating page because its title is invalid.");
      return false;
    }

    if ($page->template != $template) {
      $this->error("ERROR: error updating page because its template does not match.");
      return false;
    }
    $pt = wire('templates')->get($template);

    $this->message("Updating page '{$page->title}'[{$page->id}]", Notice::debug);

    if (count($field_data)) foreach ($field_data as $field => $fdata) {
      if (!$pt->hasField($field)) {
        $this->error("ERROR: template '{$template}' has no field named '{$field}'.");
        return false;
      }

      // get the field config
      $fconfig = $pt->fields->get($field);

      // if we got an array of values then process all of them
      if (is_array($fdata)) foreach ($fdata as $value) {
        $ret = $this->setFieldValue($page, $fconfig, $field, $value, $overwrite);
      } else {
        $ret = $this->setFieldValue($page, $fconfig, $field, $fdata, $overwrite);
      }
      if ($ret === false) return false;
    }

    try {
      if (!$page->save()) {
        $this->error("ERROR: error saving modified page '{$title}'.");
      }
    } catch (\Exception $e) {
      // TODO very long page titles may cause problems while saving the page
      // "Unable to generate unique name for page 0"
      $this->error("ERROR: failed to update page '{$title}'.");
      $this->message($e->getMessage());
      return false;
    }
    // $this->message("{$page->title} [{$template}] updated.", Notice::debug);

    return $page;
  }


/***********************************************************************
 * UTILITY METHODS
 **********************************************************************/
  /**
   * Load and return data set or file configuration.
   * 
   * @param $yconfig configuration in YAML form
   * @returns configuration as associative array or false on error
   */
  public function parseConfig($yconfig) {
    $ret = yaml_parse(self::DEF_IMPORT_OPTIONS);
    $valid_sections = array_keys($ret);

    // return default values if the config is empty
    if (strlen($yconfig)==0) return $ret;

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
    if ($fconfig->type instanceof FieldtypePage) {    // Page reference
      $selector = $this->getPageSelector($fconfig, $value);
      $this->message("Page selector @ field {$field}: {$selector}.", Notice::debug);
      $refpage = $this->pages->findOne($selector);
      if ($refpage instanceof Page && ($refpage->id > 0)) {
        $this->message("Found referenced page '{$refpage->title}' for field '{$field}' using the selector '{$selector}'.", Notice::debug);
        $value = $refpage->id;
        $hasValue = ($page->$field ? $page->$field->has($selector) : false);
        $this->message("Field '{$field}' " . ($hasValue ? 'already contains' : 'does not contain') . " the value {$value}.", Notice::debug);
      } else {
        $this->error("WARNING: referenced page with value '{$value}' not found for field '{$field}' using selector '{$selector}'.");
        return false;
      }
    } elseif ($fconfig->type instanceof FieldtypeFile
        || $fconfig->type instanceof FieldtypeImage) {    // Images and files
      $hasValue = $page->$field->has($value);
    } elseif (is_numeric($value) && $fconfig->type instanceof FieldtypeOptions) {   // Numeric options
      // if the value is numeric we can't use it as a field value on options fields
      // See https://processwire.com/api/modules/select-options-fieldtype/#manipulating-options-on-a-page-from-the-api
      // So... replace $value with an option ID if possible
      $value = $this->getOptionsFieldValue($fconfig, $value);
      $hasValue = ($page->$field ? $page->$field->has($value) : false);
    } else {
      $hasValue = $page->$field ? true : false;
    }

    if ($overwrite) {
      $this->message("Overwriting field '{$field}''s old value with '{$value}'.", Notice::debug);
      $page->$field = $value;
    } else if (!$hasValue) {
      if ($page->$field && $page->$field instanceof WireArray && $page->$field->count()) {
        $this->message("Adding new value '{$value}' to field '{$field}'.", Notice::debug);
        $page->$field->add($value);
      } else {
        $this->message("Setting field '{$field}' = '{$value}'.", Notice::debug);
        $page->$field = $value;
      }
    } else {
      $this->message("WARNING: not updating already populated field '{$field}'.", Notice::debug);
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
      if ($page instanceof NullPage) {
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
