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
  // file tags
  const TAG_IMPORT='import';  // import data from sources
  const TAG_MERGE='merge';    // merge new data with already existing data
  const TAG_OVERWRITE='overwrite'; // merge and overwrite already existing data with new imports
  const TAG_DELETE='delete';  // delete data found in the source
  const TAG_PURGE='purge';    // purge the data set before import
  const DEF_IMPORT_OPTIONS = '
name: Default import configuration
input:
  type: csv
  delimiter: \',\'
  header: 1
  enclosure: \'"\'
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
      // hook after page save to import dataset entries
      $this->addHookAfter('Page(template='.$t.')::changed('.$this->sourcefield.')', $this, 'handleSourceChange');
      // hook to check global configuration changes on dataset pages
      $this->addHookAfter('Page(template='.$t.')::changed('.$this->configfield.')', $this, 'validateConfigChange');
    }
  }



/***********************************************************************
 * HOOKS
 **********************************************************************/

  /**
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

  /**
   * Hook that validates configuration changes
   */
  public function validateConfigChange(HookEvent $event) {
    $field = $event->object;
    if (!is_object($field)) return;

    if ($field->name != $this->configfield) return;

    $page = $this->modules->ProcessPageEdit->getPage();

    $field->message("Field '{$field->name}' changed on '{$page->title}'.", Notice::debug);

    if (strlen($field->value)==0) return;

    $dataSetConfig = $this->parseConfig($field->value);
    if (false === $dataSetConfig) {
      $field->error('Invalid data set confguration.');
      $field->message($field->value.' interpreted as '.print_r($dataSetConfig, true), Notice::debug);
      return;
    }

    $field->message('Data set configuration seems to be OK.');
    $field->message('Config: '.print_r($dataSetConfig, true), Notice::debug);
  }


/***********************************************************************
 * TASK MANAGEMENT
 **********************************************************************/

  /**
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

  /**
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
   * Import a data set - a Tasker task
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $taskData task data assoc array
   * @param $params runtime paramteres, e.g. timeout, dryrun, estimation and task object
   * @returns false on error, a result message on success
   * The method also alters elements of the $taskData array.
   */
  public function import($dataSetPage, &$taskData, $params) {
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
    }

    return true;
  }


  /**
   * Purge the entire data set by removing all its child nodes
   * 
   * @param $dataSetPage ProcessWire Page object (the data set)
   * @param $taskData task data assoc array
   * @param $params runtime paramteres, e.g. timeout, estimation and task object
   * @returns false on error, a result message on success
   */
  public function purge($dataSetPage, &$taskData, $params) {
    /* remove the global config?
    $dataSetConfig = $this->parseConfig($dataSetPage->{$this->configfield});
    if ($dataSetConfig===false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return false;
    }*/

    // determine what should be purged
    $files = $dataSetPage->{$this->sourcefield}->find('tags*='.self::TAG_PURGE);
    $templates = array();
    foreach ($files as $file) if ($file->hasTag(self::TAG_PURGE)) {
      $fileConfig = $this->parseConfig($file->description);
      $templates[] = $fileConfig['pages']['template'];
    }
    if (!count($templates)) {
      $taskData['task_done'] = 1;
      $this->message('Nothing to purge.');
      return true;
    }

    $selector = 'parent='.$dataSetPage->id.',template='.implode('|', $templates).',include=all';
    $this->message("Purging '{$dataSetPage->title}' using selector '{$selector}'.", Notice::debug);

    // calculate the task's actual size
    $tsize=$this->pages->count($selector);

    // return the task size if requested
    if (isset($params['estimation'])) return $tsize;

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

    foreach ($children as $child) {
      $taskData['records_processed']++;
      $deleted[] = $child->title;
//      $child->trash();  // probably not a good idea to fill the trash
      $child->delete(true); // delete children as well

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData, $params) && count($deleted)) {
        $this->message('Deleted pages: '.implode(', ', $deleted));
        // set a new milestone
        $taskData['milestone'] = $taskData['records_processed'] + 50;
        // clear the deleted pages array (the have been already reported in the log)
        $deleted = array();
      }
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
   * @param $template template name
   * @param $selector PW selector to check whether page already exists
   * @param $field_data assoc array of field name => value pairs to be set
   * @param $tags command options: IMPORT, MERGE, OVERWRITE, DELETE (file tags)
   * 
   * @returns PW Page object that has been added/updated, NULL otherwise
   */
  public function importPage(Page $dataSetPage, $template, $selector, $field_data, $tags = array()) {
    $dataSetConfig = $this->parseConfig($dataSetPage->{$this->configfield});
    if ($dataSetConfig===false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return NULL;
    }

    // check the page selector
    if (strlen($selector)<2 || !strpos($selector, '=')) {
      $this->error("ERROR: invalid page selector '{$selector}' found in the input.");
      return NULL;
    }

    // check the page title
    if (!isset($field_data['title']) || strlen($field_data['title'])<1) {
      $this->error("ERROR: invalid / empty title '{$field_data['title']}' found in '{$selector}'.");
      return NULL;
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

    $dataPage = $dataSetPage->child($selector);

    if ($dataPage->id) { // found a page with the same title
      if (isset($tags[self::TAG_MERGE]) || isset($tags[self::TAG_OVERWRITE])) {
        return $this->updatePage($dataPage, $template, $field_data, $tags);
      } else {
        $this->message("WARNING: not updating already existing data in '{$dataPage->title}'.");
        return NULL;
      }
    }

    if (isset($tags[self::TAG_IMPORT])) { // create a new page if needed
      return $this->createPage($dataSetPage, $template, $field_data['title'], $field_data);
    } else {
      $this->error("ERROR: not importing '{$title}' because the import tag is not found at the source file.");
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
   */
  public function createPage(Page $parent, $template, $title, $field_data = array()) {
    if (!is_object($parent) || ($parent instanceof NullPage)) {
      $this->error("ERROR: error creating new {$template} named '{$title}' since its parent does not exists.");
      return NULL;
    }
    if (!is_string($title) || mb_strlen($title)<2) {
      $this->error("ERROR: error creating new {$template} named '{$title}' since its title is invalid.");
      return NULL;
    }
    // parent page needs to have an ID, get one by saving it
    if (!$parent->id) $parent->save();
    $p = $this->wire(new Page());
    if (!is_object($p)) {
      $this->error("ERROR: error creating new page named {$title} from {$template} template.");
      return NULL;
    }
    $p->template = $template;
    $pt = wire('templates')->get($template);
    if (!is_object($pt)) {
      $this->error("ERROR: template '{$template}' does not exists.");
      return NULL;
    }

    $p->parent = $parent;
    $p->title = $title;

    // TODO can we save the page now?
    $externals = array(); // fields storing external files or images
    if (count($field_data)) foreach ($field_data as $field => $value) {
      if ($field == 'title') continue;
      if (!$pt->hasField($field)) {
        $this->error("ERROR: template '{$template}' has no field named '{$field}'.");
        return NULL;
      }
      if ($pt->fields->get($field)->type instanceof FieldtypeFile
          || $pt->fields->get($field)->type instanceof FieldtypeImage) {
        // We can't add files to pages that are not saved. We'll do this later.
        $externals[$field] = $value;
      } else {
        $p->$field = $value;
      }
    }

// TODO multi-language support for pages?
/*
    $p->of(false); // turn of output formatting

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
      $p->save(); // pages must be saved to be a parent or to be referenced
    } catch (\Exception $e) {
      // TODO very long page titles may cause problems while saving the page
      // "Unable to generate unique name for page 0"
      $this->error("ERROR: failed to create page '{$title}'.");
      $this->message($e->getMessage());
      return NULL;
    }
    // $this->message("{$parent->title} / {$title} [{$template}] created.", Notice::debug);

    // after the page is saved we can download and attach external files and images
    if (count($externals)) foreach ($externals as $field => $value) {
      $this->message("Downloading and adding {$value} to {$field}.", Notice::debug);
      $p->$field->add($value);
    }
    $p->save();

    return $p;
  }



  /**
   * Update a Processwire Page and set its fields.
   * 
   * @param $page the parent node reference
   * @param $template the template of the updated page
   * @param $fields assoc array of field name => value pairs to be set
   */
  public function updatePage(Page $page, $template, $title, $field_data = array(), $tags = array()) {
    if (!is_object($page) || ($page instanceof NullPage)) {
      $this->error("ERROR: error updating page because it does not exists.");
      return false;
    }

    // check if there is anything to update
    if (!is_array($field_data) || !count($field_data)) return true;

    // check the page title
    if (!isset($field_data['title']) || mb_strlen($field_data['title'])<2) {
      $this->error("ERROR: error updating page because its title is invalid.");
      return false;
    }

    if ($page->template != $template) {
      $this->error("ERROR: error updating page because its template does not match.");
      return false;
    }
   $pt = wire('templates')->get($template);

   $this->message("Updating page '{$page->title}'[{$page->id}]", Notice::debug);

   foreach ($field_data as $field => $value) {
      if (!$pt->hasField($field)) {
        $this->error("ERROR: template '{$template}' has no field named '{$field}'.");
        return false;
      }
      // TODO handle arrays and special fields like files or images?
      if ($page->$field && $page->field != $value && isset($tags[self::TAG_OVERWRITE])) {
        $this->error("WARNING: overwriting field '{$field}''s old value '{$page->field}' with '{$value}'.");
        $p->$field = $value;
      } else {
        $this->message("WARNING: not updating already existing data on page '{$page->title}' in field '{$field}'.");
      }

      // TODO multi-language support?
    }

    try {
      $p->save(); // pages must be saved to be a parent or to be referenced
    } catch (\Exception $e) {
      // TODO very long page titles may cause problems while saving the page
      // "Unable to generate unique name for page 0"
      $this->error("ERROR: failed to update page '{$title}'.");
      $this->message($e->getMessage());
      return NULL;
    }
    // $this->message("{$page->title} [{$template}] updated.", Notice::debug);

    return $p;
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
      throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
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
        // TODO validate settings
        $ret[$section][$setting] = $value;
      } else {
        $ret[$section] = $values;
      }
    }

    // $this->message("DataSet config '{$yconfig}' was interpreted as ".var_export($ret, true).'.', Notice::debug);

    return $ret;
  }
}
