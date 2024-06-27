Databasedump-Import
====================

This web application allows you to import a database dump (e.g., mysqldump) into an existing database.

After the database dump is uploaded, a configuration table appears with the following options:
* Listing of all database tables and fields with the ability to select the fields to be imported
* Definition of relations to your own or other tables for each field
* Specification of static values for each field

Notes:
* If data is to be imported into existing tables with an auto-increment field, deselect the auto-increment field in the import configuration.
* When specifying relations, the auto-increment fields in the database dump are automatically identified.


Installation
------------
* Download files in WEBROOT/dbdumpimport/
* Update .htaccess if don't use WEBROOT/dbdumpimport as the location of this web application
* Update config.php (or better generate config_local.php) and set file path and database configuration


Filesystem
----------
```bash
/config
    config.php
    config_local.php [optional, in .gitignore]
    importconfig.php
/controllers
    ConfigController.php
    ImportController.php
/importedfiles [content of folder .gitignore]
    .keep [to enable empty folder]
    dbdump.sql [example file]
/models
    Database.php
/routes
    routes.php
/uploads
    index.php
index.php
README.md
```


Last Update
-----------
2024-06-27 v1.0



---

Description in german:

Mit dieser Webapplikation ist es möglich, einen Datenbank-Dump (z.B. mysqldump) in eine bestehende Datenbank zu importieren.

Nachdem der Datenbank-Dump hochgeladen wurde, erscheint eine Konfigurationstabelle mit folgenden Optionen:
* Auflistung aller Datenbanktabellen und Felder mit der Möglichkeit, die zu importierenden Felder auszuwählen
* Definition von Relationen zu eigenen oder zu anderen Tabellen für jedes Feld
* Angabe statischer Werte für jedes Feld

Hinweise:
* Wenn Daten in bestehende Tabellen mit einem Autoincrement-Feld importiert werden sollen, das Autoincrement-Feld in der Import-Konfiguration deselektieren.
* Bei der Angabe von Relationen werden die Autoincrement-Felder im Datenbank-Dump automatisch ermittelt.
