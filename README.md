# DataSet
It is a set of ProcessWire modules for importing, manipulating and displaying large (50k+ entries) data sets.  
The software was developed for the [Mikes-dictionary] and other Digital Humanities projects.

## Main features
* import data from CSV and XML sources
* purge, extend or overwrite existing data (PW pages)
* user configurable input <-> field mappings and on-the-fly field data composition
* supports downloading external resources (files, images)
* fairly low memory requirements (uses [Tasker](https://github.com/mtwebit/Tasker) to execute long-running jobs)

## How to use it
See the [wiki](https://github.com/mtwebit/DataSet/wiki).

## Important notice
This module is under continuous development.  
It is now considered fairly stable but things may be broken and the internal API may change at any time.  

## History
The first version was created in 2017 to import a large XML dataset into ProcessWire pages.  
The CSV import sub-module was created in 2018. It was tested to import large dataset containing 200k+ entries and many kinds of references between them.

## License
The "github-version" of the software is licensed under MPL 2.0.
