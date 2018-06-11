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
  const TAG_UPDATE='update';  // update already existing data
  const TAG_DELETE='delete';    // delete data found in the source
  const TAG_PURGE='purge';    // purge the data set before import

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
    // TODO check if Tasker is available

    // install a conditional hook after page save to import dataset entries
    // Note: PW 3.0.62 has a bug and needs manual fix for conditional hooks:
    // https://github.com/processwire/processwire-issues/issues/261
    if (is_array($this->dataset_templates)) foreach ($this->dataset_templates as $t)
      $this->addHookAfter('Page(template='.$t.')::changed('.$this->sourcefield.')', $this, 'handleSourceChange');
    $this->addHookAfter('InputfieldTextarea::processInput', $this, 'validateConfigChange');
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
    // TODO also check for filefield description errors

    $page = $this->modules->ProcessPageEdit->getPage();

    //$field->message("Field '{$field->name}' changed on '{$page->title}'.");

    // TODO: check page template
    // if (!$this->dataset_templates->has($page->template)) return;

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
 * SETUP IMPORT TASKS
 **********************************************************************/

  /**
   * Create necessary tasks when the page is ready to save
   * 
   * @param $dataSetPage ProcessWire Page object
   */
  public function createTasksOnPageSave($dataSetPage) {
    // check if any file needs to be handled
    $files = $dataSetPage->{$this->sourcefield}->find('tags*='.self::TAG_IMPORT.'|'.self::TAG_UPDATE.'|'.self::TAG_PURGE.'|'.self::TAG_DELETE);
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
      $task = $tasker->createTask(__CLASS__, 'purge', $dataSetPage, 'Purge the data set', $data);
      if ($purgeTask == NULL) return; // tasker failed to add a task
      $data['dep'] = $purgeTask->id; // add a dependency to import tasks: first delete old entries
      $firstTask = $prevTask = $purgeTask;
      $this->message("Created a task to purge data set before import.", Notice::debug);
    }

    // create an import task for each input file
    foreach ($files as $name => $file) {
      $data['file'] = $name;
      $title = 'Process data found in '.$name;
      if (!$file->hasTag(self::TAG_IMPORT.'|'.self::TAG_UPDATE.'|'.self::TAG_DELETE)) {
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

/*
    // TODO this is only for debugging: execute the first task right now
    $tasker->executeTask($firstTask);
    // TODO debug: dump out all messages
    foreach (explode("\n", $firstTask->log_messages) as $msg) {
      $this->message($msg);
    }
*/

    // TODO this is only for debugging: execute the first task right now
    $this->redirectUrl = wire('config')->urls->admin.'page/tasks/?id='.$firstTask->id.'&cmd=run';
    // add a temporary hook to redirect to TaskerAdmin's monitoring page after saving the current page
    $this->pages->addHookBefore('ProcessPageEdit::processSaveRedirect', $this, 'runDataSetTasks');

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
   * Import a data set - a PW task
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

    // check if we still have the file and its tag
    $file=$dataSetPage->{$this->sourcefield}->findOne('name='.$taskData['file'].',tags*='.self::TAG_IMPORT.'|'.self::TAG_UPDATE.'|'.self::TAG_DELETE);
    if ($file==NULL) {
      $this->error("ERROR: input file '".$taskData['file']."' is not present or has no tags on '{$dataSetPage->title}'.");
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
        $this->warning("NOTICE: content type of {$fileConfig['name']} is not {$fileConfig['input']['type']} but {$ctype}. Processing anyway.");
      }
      $proc = $this->modules->getModule('DataSetXmlProcessor');
      break;
    case 'csv':
      // try to validate the content type when the task starts
      if (!$taskData['records_processed'] && !strpos($ctype, 'csv')) {
        $this->warning("NOTICE: content type of {$fileConfig['name']} is not {$fileConfig['input']['type']} but {$ctype}. Processing anyway.");
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
      return 'Import is done (input is empty).';
    }

    $this->message("Processing file {$file->name}.", Notice::debug);

    // import the data set from the file
    $ret = $proc->process($dataSetPage, $file, $taskData, $params);
    if ($ret === false) return false;

    // check if the file has been only partially processed (e.g. due to max exec time is reached)
    if ($taskData['offset'] != 0) {
      return 'The file was only partially processed.';
    }

    if ($taskData['records_processed'] != $taskData['max_records']) {
      $this->warning('NOTICE: DataSet import assertion failed: all files are done but not all records processed. '
        . "Processed: {$taskData['records_processed']} =/= Max: {$taskData['max_records']}");
    }

    // file is ready, report back that task is done
    $taskData['task_done'] = 1;

    return $taskData['file'].' has been processed.';
  }


  /**
   * Purge the data set by removing all its child nodes
   * 
   * @param $dataSetPage ProcessWire Page object (the data set)
   * @param $taskData task data assoc array
   * @param $params runtime paramteres, e.g. timeout, estimation and task object
   * @returns false on error, a result message on success
   */
  public function purge($dataSetPage, &$taskData, $params) {
    $dataSetConfig = $this->parseConfig($dataSetPage->{$this->configfield});
    if ($dataSetConfig===false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return false;
    }

    // determine what should be purged
    $files = $dataSetPage->{$this->sourcefield}->find('tags*='.self::TAG_PURGE);
    $templates = array();
    foreach ($files as $file) if ($file->hasTag(self::TAG_PURGE)) {
      $fileConfig = $this->parseConfig($file->description);
      $templates[] = $fileConfig['pages']['template'];
    }
    if (!count($templates)) return 'Nothing to purge.';

    $selector = 'parent='.$dataSetPage->id.',template='.implode('|', $templates).',include=all';

    // calculate the task's actual size
    $tsize=$this->pages->count($selector);

    // return the task size if requested
    if (isset($params['estimation'])) return $tsize;

    // initialize task data if this is the first invocation
    if ($taskData['records_processed'] == 0) {
      // estimate the number of processable records
      $taskData['max_records'] = $tsize;
      $taskData['records_processed'] = 0;
    }

    // check if we have nothing to do
    if ($tsize==0) {
      $taskData['task_done'] = 1;
      return 'Done deleting data entries.';
    }

    $taskData['task_done'] = 1; // we're optimistic that we could finish the task

    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
    $task = $params['task'];

    // store a few page names to print out
    $deleted = array();

    $children = $this->pages->findMany($selector);

    foreach ($children as $child) {
      $taskData['records_processed']++;
      $deleted[] = $child->title;
//      $child->trash();  // probably not a good idea to fill the trash
      $child->delete(true); // delete children as well

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData)) {
        $this->message('Deleted pages: '.implode(', ', $deleted));
        $deleted = array();
      }
      if (!$tasker->isActive($task)) {
        $this->warning('The data set is not purged entirely since the task is no longer active.', Notice::debug);
        $taskData['task_done'] = 0;
        break; // the foreach loop
      }
      if ($params['timeout'] && $params['timeout'] <= time()) { // allowed execution time is over
        $this->warning('The data set is not purged entirely since maximum execution time is too close.', Notice::debug);
        $taskData['task_done'] = 0;
        break;  // the while loop
      }
    } // foreach pages to delete

    $this->message('Deleted pages: '.implode(', ', $deleted));

    if ($taskData['task_done']) {
      return 'Done deleting data set entries.';
    }

    return 'Purge is not finished.';
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
   * @param $fields assoc array of field name => value pairs to be set
   * @param $tags command options: IMPORT, UPDATE, DELETE old version first (coming from file tags)
   * 
   * @returns PW Page object that has been added/updated, NULL otherwise
   */
  public function importPage(Page $dataSetPage, $template, $selector, $field_data, $tags = array()) {
    $dataSetConfig = $this->parseConfig($dataSetPage->{$this->configfield});
    if ($dataSetConfig===false) {
      $this->error("ERROR: invalid data set configuration on page '{$dataSetPage->title}'.");
      return false;
    }

    // check the page selector
    if (strlen($selector)<2 || !strpos($selector, '=')) {
      $this->error("ERROR: invalid selector '{$selector}' found in the input.");
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
      if (isset($tags[self::TAG_UPDATE])) { // update the existing page
        $this->message("Updating page '{$dataPage->title}'[{$dataPage->id}]", Notice::debug);
        // TODO update fields
        return $dataPage;
      } else {
        $this->message("NOTICE: not updating already existing data in '{$dataPage->title}'.");
        return NULL;
      }
    }

    if (isset($tags[self::TAG_IMPORT])) { // create a new page if needed
      return $this->createPage($dataSetPage, $template, $field_data['title'], $field_data);
    } else {
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
      $this->error("ERROR: error creating new {$template} named {$title} since its parent does not exists.");
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
    $p->parent = $parent;
    $p->title = $title;
    if (count($field_data)) foreach ($field_data as $field => $value) {
      if ($field == 'title') continue;
      // if ($p->hasField($field)) $p->$field = $value;
      $p->$field = $value;
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

    // $this->message("{$parent->title} / {$title} [{$template}] created.", Notice::debug);
    $p->save(); // pages must be saved to be a parent or to be referenced
    return $p;
  }



/***********************************************************************
 * UTILITY METHODS
 **********************************************************************/
  /**
   * Load and return data set or file configuration.
   * 
   * @param $config configuration in YAML form
   * @returns configuration as associative array
   */
  public function parseConfig($config) {
    if (strlen($config)==0) return array(); // TODO default values?
    return yaml_parse($config);
  }
}
