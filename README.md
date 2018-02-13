# DataSet
ProcessWire modules for importing and handling large data sets.

## Notes
Use with caution: it is under continuous development. Things may be broken and the API may change at any time.  
PW 3.0.62 has a bug and needs manual fix for conditional hooks.
See [this issue](https://github.com/processwire/processwire-issues/issues/261) for a fix or upgrade to the latest dev.

## Purpose
These modules provides support for importing, storing, manipulating and displaying large number of data entries in ProcessWire.  
They can handle 50k+ entries with low memory requirements. Longer tasks are run using the [Tasker](https://github.com/mtwebit/Tasker) module.  
The software was developed for the [Mikes-dictionary] and other Digital Humanities projects.

## Installation
First, ensure that your ProcessWire installation and the underlying database meets the encoding and indexing requirements of your projects. Their default settings probably won't work for you if you store materials in languages other than English. See [Encoding.md](https://github.com/mtwebit/DataSet/blob/master/Encoding.md) for more details.  
After creating your ProcessWire site, install the module the usual way and follow the instructions on the module's config page.

## How to import data
TODO

## License
The "github-version" of the software is licensed under MPL 2.0.  
