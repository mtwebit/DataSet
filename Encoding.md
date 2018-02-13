# Solving character encoding and search issues

## ProcessWire
Ensure that you use UTF8 encoding whenever it is applicable.  
Configuration settings:  
* $config->pageNameCharset = 'UTF8';
* $config->pageNameWhitelist = '... set it properly ...'; // the default is not good enough for some charsets

Also set the .htaccess file if needed. See [this](https://processwire.com/blog/posts/hello-%E5%81%A5%E5%BA%B7%E9%95%B7%E5%A3%BD%C2%B7%E7%B9%81%E6%A6%AE%E6%98%8C%E7%9B%9B/) post.  
You can check the results by issuing these commands (using e.g. Tracy Debugger's console):  
* $str = 'éáőúóüö'; // put your custom chars here
* d($str);
* d($sanitizer->pageNameUTF8($str, true));

## MySQL
In addition to choosing utf8 encoding you also have to set proper database collation.  
The default is usually utf8_general_ci which handles texts if they were all ASCII and have no accents which is bad.  
Check the collation:
* SHOW VARIABLES LIKE 'collation%';
* SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME  FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='your-pw-db-name';

Choose and set the proper collation (e.g. utf8_hungarian_ci) for field_ tables and columns.
(PhpMyAdmin might be a good choice for this task.)  
