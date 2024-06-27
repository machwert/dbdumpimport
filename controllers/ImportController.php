<?php

class ImportController
{
    private $db;
    private $config;
    private $sql;
    private $outputArray;

    public function __construct($db)
    {
        $this->db = $db;
        $this->config = require __DIR__ . '/../config/config.php';

        if (file_exists(__DIR__ . '/../config/config_local.php')) {
            $configLocal = require __DIR__ . '/../config/config_local.php';
            if (isset($configLocal)) {
                $this->config = array_merge($this->config, $configLocal);
            }
        }
    }

    public function importData($importConfig)
    {
        $importPath = $importConfig['import_file']['path'];
        $importFilename = $importConfig['import_file']['filename'];
        $importFile = $importPath . '/' . $importFilename;

        if (!isset($this->sql)) {
            if (!isset($importConfig['import_file']['path']) || !isset($importConfig['import_file']['filename'])) {
                die("Fehler: Der Dateipfad zur Importdatei ist nicht korrekt in der Konfigurationsdatei definiert.");
            }

            if (!file_exists($importFile) || !is_readable($importFile)) {
                die("Fehler: Die Importdatei '$importFile' konnte nicht gelesen werden.");
            }

            $this->sql = file_get_contents($importFile);
            if ($this->sql === false) {
                die("Fehler: Die Importdatei '$importFile' konnte nicht gelesen werden.");
            }
        }

        $newIds = [];
        $excludedFields = $this->getExcludedFields($importConfig);
        $relationFields = $this->getRelationFields($importConfig);
        $staticvalueFields = $this->getStaticvalueFields($importConfig);
        $tableDataArray = $this->parseSqlFile($importFile);

        foreach ($importConfig['tables'] as $table => $tableConfigData) {

            $primaryField = '';

            if (!$tableConfigData['exists']) {
                $createSql = $this->getCreateTableSql($table, $importConfig);
                if ($createSql) {
                    if (!$this->tableExists($table)) {
                        $this->db->query($createSql);
                        $alterSqlArray = $this->getAlterTableSql($table);
                        if (isset($alterSqlArray)) {
                            foreach ($alterSqlArray as $alterSql) {
                                $this->db->query($alterSql);
                                $primaryFieldTemp = $this->getPrimaryField($alterSql);
                                if ($primaryFieldTemp != '') {
                                    $primaryField = $primaryFieldTemp;
                                }
                            }
                        }
                    }
                    $tableConfigData['exists'] = true;
                } else {
                    echo "Fehler: CREATE TABLE SQL für Tabelle $table nicht gefunden.<br>";
                    continue;
                }
            } else {
                $alterSqlArray = $this->getAlterTableSql($table);
                if (isset($alterSqlArray)) {
                    foreach ($alterSqlArray as $alterSql) {
                        $primaryFieldTemp = $this->getPrimaryField($alterSql);
                        if ($primaryFieldTemp != '') {
                            $primaryField = $primaryFieldTemp;
                        }
                    }
                }
            }

            $this->importTableData($table, $tableDataArray, $excludedFields, $relationFields, $staticvalueFields, $primaryField, $newIds);
        }

        echo "<p>All tables and datasets were successfully imported.</p>";

        echo '<p></p><a class="btn" href="' . $this->config['base_url'] . '">&laquo; Back</a></p>';

    }

    private function tableExists($table)
    {
        $escapedTable = $this->db->real_escape_string($table);
        $query = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$escapedTable' LIMIT 1";
        $result = $this->db->query($query);
        return $result && $result->num_rows > 0;
    }

    private function getCreateTableSql($table, $config)
    {
        preg_match('/CREATE TABLE `?' . $table . '`? \((.*?)\)(?=\s*(?:ENGINE|;))/is', $this->sql, $matches);
        if (!empty($matches)) {
            return 'CREATE TABLE ' . $table . ' (' . $matches[1] . ')';
        }
        return null;
    }

    private function getAlterTableSql($table)
    {
        $pattern = '/ALTER TABLE `' . $table . '`[^;]*;/';
        preg_match_all($pattern, $this->sql, $alterTableMatches);
        return $alterTableMatches[0];
    }

    private function getPrimaryField($alterSql)
    {

        $pattern = "/ALTER TABLE\s+`([^`]+)`\s+MODIFY\s+`([^`]+)`[^;]+AUTO_INCREMENT[^;]*;/";

        if (preg_match_all($pattern, $alterSql, $matches)) {
            $tableNames = $matches[1];
            $fieldNames = $matches[2];
            return $fieldNames[0];
        }
        return '';
    }

    private function getExcludedFields($config)
    {
        $excludedFields = [];
        foreach ($config['tables'] as $table => $tableData) {
            foreach ($tableData['fields'] as $field => $fieldData) {
                if (!$fieldData['import']) {
                    if (!isset($excludedFields[$table])) {
                        $excludedFields[$table] = [];
                    }
                    $excludedFields[$table][] = '`' . $field . '`';
                }
            }
        }
        return $excludedFields;
    }

    private function getRelationFields($config)
    {
        $relationFields = [];
        foreach ($config['tables'] as $table => $tableData) {
            foreach ($tableData['fields'] as $field => $fieldData) {
                if (isset($fieldData['relation']) && !empty($fieldData['relation'])) {
                    if (!isset($relationFields[$table])) {
                        $relationFields[$table] = [];
                    }
                    $relationFields[$table]['`' . $field . '`'] = $fieldData['relation'];
                }
            }
        }
        return $relationFields;
    }

    private function getStaticvalueFields($config)
    {
        $relationFields = [];
        foreach ($config['tables'] as $table => $tableData) {
            foreach ($tableData['fields'] as $field => $fieldData) {
                if (isset($fieldData['staticvalue']) && !empty($fieldData['staticvalue'])) {
                    if (!isset($relationFields[$table])) {
                        $staticvalueFields[$table] = [];
                    }
                    $staticvalueFields[$table]['`' . $field . '`'] = $fieldData['staticvalue'];
                }
            }
        }
        return $staticvalueFields;
    }

    private function parseSqlFile($filePath)
    {
        $sqlContent = file_get_contents($filePath);
        $tableContent = [];
        $pattern = '/INSERT INTO `(\w+)` \([^)]+\) VALUES\s+((?:(?:\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*"|[^\'"])*?));/s';
        preg_match_all($pattern, $sqlContent, $matches);

        foreach ($matches[1] as $index => $tableName) {

            // fields
            $fieldsPattern = '/INSERT INTO `' . $tableName . '`? \((.*?)\) VALUES/is';
            preg_match_all($fieldsPattern, $matches[0][$index], $fieldsMatches);
            if (isset($fieldsMatches[1][0])) {
                $fieldsBlock = $fieldsMatches[1][0];
                $fields = preg_split('/,\s*/', $fieldsBlock);

                if (!isset($tableContent[$tableName]['fieldname'])) {
                    $tableContent[$tableName]['fieldname'] = $fields;
                }
            }

            // values
            $values = $matches[2][$index];
            $valuePattern = '/\(([^\'()]*+(?:\'(?:\\\\.|[^\'])*\'[^\'()]*)*)\)/';
            preg_match_all($valuePattern, $values, $valueMatches);
            foreach ($valueMatches[1] as $i => $valueSet) {
                $tableContent[$tableName]['fieldvalue'][] = $this->explode_ignore_quotes(',', $valueSet);
            }
        }

        foreach ($tableContent as $tableName => $tableData) {
            foreach ($tableData['fieldvalue'] as $datasetNr => $dataset) {
                foreach ($dataset as $fieldNr => $fieldValue) {
                    //$fieldName = str_replace("`", "", $tableContent[$tableName]['fieldname'][$fieldNr]);
                    $fieldName = $tableContent[$tableName]['fieldname'][$fieldNr];
                    $tableContent[$tableName]['fieldnamevalue'][$datasetNr][$fieldName] = $tableContent[$tableName]['fieldvalue'][$datasetNr][$fieldNr];
                }
            }
        }

        return $tableContent;
    }

    private function explode_ignore_quotes($delimiter, $string)
    {
        $pattern = '/
        (?:                         # Start non-capture group
            "([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"  # Match double-quoted strings
            | \'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'  # Match single-quoted strings
            | ([^\'",]+)              # Match non-quoted parts
        )
    /x';

        preg_match_all($pattern, $string, $matches);

        $result = [];
        foreach ($matches[0] as $match) {
            // Trim whitespace and check if the match is not empty or only a comma
            $trimmed_match = trim($match);
            if ($trimmed_match !== '' && $trimmed_match !== $delimiter) {
                $result[] = $trimmed_match;
            }
        }

        return $result;
    }

    private function importTableData($tableName, $tableDataArray, $excludedFields, $relationFields, $staticvalueFields, $primaryField, &$newIds)
    {
        if (isset($tableDataArray[$tableName])) {
            $tableData = $tableDataArray[$tableName];
        }
        if (isset($excludedFields[$tableName])) {
            $excludedTableFields = $excludedFields[$tableName];
        }
        if (isset($relationFields[$tableName])) {
            $relationTableFields = $relationFields[$tableName];
        }
        if (isset($staticvalueFields[$tableName])) {
            $staticvalueTableFields = $staticvalueFields[$tableName];
        }

        /*
        if (isset($excludedTableFields)) {
            foreach ($excludedTableFields as $key => $val) {
                $valueToFind = '`' . $val . '`';
                $excludedKey = array_search($valueToFind, $tableData['fieldname']);
                $excludedKeys[] = $excludedKey;

                if ($val === 'uid') {
                    $excludedUidKey = $excludedKey;
                }
            }
        }
        */

        if (isset($relationTableFields)) {
            foreach ($relationTableFields as $key => $val) {
                $valueToFind = $key;
                $relationKey = array_search($valueToFind, $tableData['fieldname']);

                if ($val == $tableName) {
                    $relationFieldsToUpdateAfterwards[$key] = $relationKey;
                    unset($relationTableFields[$key]);
                } else {
                    $relationKeys[$relationKey] = $val;
                }
            }
        }

        foreach ($tableData['fieldnamevalue'] as $key => $dataset) {

            if (isset($primaryField) && $primaryField != '') {
                $oldPrimaryValue[$key] = $dataset['`' . $primaryField . '`'];
            }

            if (isset($staticvalueTableFields)) {
                foreach ($staticvalueTableFields as $staticvalueTableFieldKey => $staticvalueTableFieldValue) {
                    $tableData['fieldnamevalue'][$key][$staticvalueTableFieldKey] = $staticvalueTableFieldValue;
                }
            }

            if (isset($excludedTableFields)) {
                foreach ($excludedTableFields as $excludedTableFieldKey => $excludedTableFieldValue) {
                    $excludedKey = array_search($excludedTableFieldValue, $tableData['fieldname']);
                    if ($excludedKey !== false) {
                        unset($tableData['fieldname'][$excludedKey]);
                    }
                    unset($tableData['fieldnamevalue'][$key][$excludedTableFieldValue]);
                }
            }

            if (isset($relationTableFields) && count($relationTableFields) > 0) {
                foreach ($relationTableFields as $relationTableKey => $relationTableName) {

                    /*
                    echo("Tablename: ".$tableName.'<br>');
                    echo("Relation TableName: ".$relationTableName.'<br>');
                    echo("Key:".$key.'<br>');
                    echo("Fieldvalue: ".$tableData['fieldvalue'][$key][$relationKey].'<br>');
                    echo("New Fieldvalue: ".$newIds[$relationTableName][$tableData['fieldvalue'][$key][$relationKey]].'<br><br>');
                    */

                    if (isset($tableData['fieldnamevalue'][$key][$relationTableKey]) && isset($newIds[$relationTableName][$tableData['fieldnamevalue'][$key][$relationTableKey]])) {
                        $tableData['fieldnamevalue'][$key][$relationTableKey] = $newIds[$relationTableName][$tableData['fieldnamevalue'][$key][$relationTableKey]];
                    }

                }
            }
            if (isset($relationFieldsToUpdateAfterwards)) {
                foreach ($relationFieldsToUpdateAfterwards as $relationFieldToUpdate => $relationFieldNrToUpdate) {
                    if (isset($dataset[$relationFieldToUpdate]) && is_numeric($dataset[$relationFieldToUpdate])) {
                        // [old uid][updateFieldname] = old uid value
                        $datasetsToBeUpdatedLater[$oldPrimaryValue[$key]][$relationFieldToUpdate] = $dataset[$relationFieldToUpdate];
                    }
                }
            }
        }

        foreach ($tableData['fieldnamevalue'] as $key => $dataset) {
            $insertSql = sprintf(
                "INSERT INTO `%s` (%s) VALUES (%s);",
                $tableName,
                implode(', ', $tableData['fieldname']),
                implode(', ', $tableData['fieldnamevalue'][$key])
            );

            try {
                $this->db->query($insertSql);
            } catch (Exception $e) {
                echo "Ein Fehler ist aufgetreten: " . $e->getMessage();
            }
            if (isset($oldPrimaryValue[$key])) {
                $newIds[$tableName][$oldPrimaryValue[$key]] = $this->db->insert_id;
            }

            if (isset($newIds[$tableName][$key]) && $newIds[$tableName][$key] > 0) {
                $this->outputArray[$tableName]['insert']['uid'][] = $newIds[$tableName][$key];
            } else {
                $this->outputArray[$tableName]['insert']['uid'][] = 0;
            }

        }

        if (isset($datasetsToBeUpdatedLater)) {
            foreach ($datasetsToBeUpdatedLater as $datasetUid => $oldDataField) {
                foreach ($oldDataField as $oldDataFieldName => $oldDataFieldValue) {
                    if ($oldDataFieldValue > 0 && isset($newIds[$tableName][$datasetUid]) && isset($newIds[$tableName][$oldDataFieldValue])) {
                        $datasetToBeUpdated[$oldDataFieldName][$newIds[$tableName][$datasetUid]] = $newIds[$tableName][$oldDataFieldValue];
                    }
                }
            }
        }

        if (isset($datasetToBeUpdated)) {
            foreach ($datasetToBeUpdated as $datasetToBeUpdatedFieldName => $datasetToBeUpdatedValueArray) {
                foreach ($datasetToBeUpdatedValueArray as $datasetToBeUpdatedUid => $datasetToBeUpdatedValue) {
                    // UPDATE $tableName SET $datasetToBeUpdatedFieldName = $datasetToBeUpdatedValue WHERE uid = $datasetToBeUpdatedUid;

                    $updateSql = sprintf(
                        "UPDATE `%s` SET %s = %s WHERE uid = %s;",
                        $tableName,
                        $datasetToBeUpdatedFieldName,
                        $datasetToBeUpdatedValue,
                        $datasetToBeUpdatedUid
                    );

                    $this->db->query($updateSql);

                    if ($newIds[$tableName][$key] > 0) {
                        $this->outputArray[$tableName]['update']['uid'][] = $newIds[$tableName][$key];
                    } else {
                        $this->outputArray[$tableName]['update']['uid'][] = 0;
                    }
                }
            }
        }

        // after

        /*
        foreach ($relationFieldsToUpdateAfterwards as $key => $relationFieldToUpdate) {
            foreach($newIds[$tableName] as $oldId => $newId) {
                $updateSql = sprintf(
                    "UPDATE `%s` SET (%s) = (%s) WHERE uid = (%s);",
                    $tableName,
                    $relationFieldToUpdate,
                    $newId,
                    $newId
                );

                echo(chr(10).$updateSql.chr(10));
            }

        }
        */

    }

    private function getFieldByIndex($table, $index)
    {
        $query = "SHOW COLUMNS FROM `$table`";
        $result = $this->db->query($query);
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[] = $row['Field'];
        }
        return $fields[$index] ?? null;
    }

    private function getRelatedId($relatedTable, $value, $newIds)
    {
        if (isset($newIds[$relatedTable])) {
            foreach ($newIds[$relatedTable] as $oldId => $newId) {
                if ($value == $oldId) {
                    return $newId;
                }
            }
        }
        return null;
    }
}
