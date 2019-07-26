<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * DataSet module - configuration
 * 
 * Provides data set handling for ProcessWire.
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class DataSetConfig extends ModuleConfig {

  public function getDefaults() {
    return array(
      'sourcefield' => 'sourcefield',
      'configfield' => 'configfield',
      'datasetTemplates' => 'dataset',
    );
  }

  public function getInputfields() {
    $inputfields = parent::getInputfields();

    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('Requirements');

    $f = $this->modules->get('InputfieldMarkup');
    if (!$this->modules->isInstalled('Tasker')) {
      $this->message('Tasker module is missing.', Notice::warning);
      $out = '<p>Tasker module is missing. Install it before using this module.</p>';
    } else {
      $out = "<p>Good! Tasker module is installed.</p>";
    }
    $f->value = $out;
    $fieldset->add($f);

    $inputfields->add($fieldset);

/********************  Template settings ******************************/
    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('Template Setup');

    $f = $this->modules->get('InputfieldSelectMultiple');
    $f->attr('name', 'datasetTemplates');
    $f->label = 'Data set templates';
    $f->description = __('These are the root elements of data sets. They should contain a source and a config field.');
    $f->options = array();
    $f->required = true;
    $f->columnWidth = 100;
    foreach($this->wire('templates') as $template) {
      foreach($template->fields as $field) {
        if ($field->type instanceof FieldtypeFile) {
          $f->addOption($template->name, $template->name);
        }
      }
    }
    $fieldset->add($f);

    $inputfields->add($fieldset);


/********************  Field name settings ****************************/
    $fieldset = $this->wire('modules')->get("InputfieldFieldset");
    $fieldset->label = __("Field setup");

    $f = $this->modules->get('InputfieldSelect');
    $f->attr('name', 'sourcefield');
    $f->label = 'Field that contains data set source files.';
    $f->description = __('The field should support the use of "import", "delete" and "update" tags.');
    $f->options = array();
    $f->required = true;
    $f->columnWidth = 50;
    foreach ($this->wire('fields') as $field) {
      if (!$field->type instanceof FieldtypeFile) continue;
      $f->addOption($field->name, $field->label);
    }
    $fieldset->add($f);

    $fieldset->label = __("Field setup");

    $f = $this->modules->get('InputfieldSelect');
    $f->attr('name', 'configfield');
    $f->label = 'Field that contains data set global configuration.';
    $f->description = __('DataSet uses YAML format to describe the configuration.');
    $f->options = array();
    $f->required = true;
    $f->columnWidth = 50;
    foreach ($this->wire('fields') as $field) {
      if (!$field->type instanceof FieldtypeTextarea) continue;
      $f->addOption($field->name, $field->label);
    }
    $fieldset->add($f);

    $inputfields->add($fieldset);

    return $inputfields;
  }
}
