<?php

//require_once __DIR__ . '/../config/config.php'; // Pfad zur config.php anpassen, falls nötig

class ConfigController
{
    private $db;
    private $config;
    private $importConfig;

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

        $importConfigPath = __DIR__ . '/../config/importconfig.json';
        if (file_exists($importConfigPath)) {
            $this->importConfig = json_decode(file_get_contents($importConfigPath), true);
        } else {
            $this->importConfig = [];
        }
    }

    public function generateConfig($sql)
    {
        preg_match_all('/CREATE TABLE `?(\w+)`? \((.*?)\)\s*(?:ENGINE|;)/is', $sql, $matches);

        $matches[1] = array_unique($matches[1]);
        $matches[2] = array_unique($matches[2]);

        $config = ['tables' => []];

        foreach ($matches[1] as $index => $table) {
            $fields = $this->parseFields($matches[2][$index]);
            $tableExists = $this->db->query("SHOW TABLES LIKE '$table'")->num_rows > 0;

            $config['tables'][$table] = [
                'exists' => $tableExists,
                'fields' => []
            ];

            foreach ($fields as $field) {
                $fieldExists = false;
                if ($tableExists) {
                    $result = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '$field'");
                    $fieldExists = $result && $result->num_rows > 0;
                }
                $config['tables'][$table]['fields'][$field] = [
                    'exists' => $fieldExists,
                    'import' => true,
                    'relation' => null,
                    'staticvalue' => null
                ];
            }
        }
        return $config;
    }

    public function updateTableAndFieldStatus($config) {
        //print_r($config);
        foreach ($config['tables'] as $table => $tableData) {
            $fields = $tableData['fields'];
            $tableExists = $this->db->query("SHOW TABLES LIKE '$table'")->num_rows > 0;
            $config['tables'][$table]['exists'] = $tableExists;

            foreach ($fields as $field => $fieldData) {
                $fieldExists = false;
                if ($tableExists) {
                    $result = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '$field'");
                    $fieldExists = $result && $result->num_rows > 0;
                }
                $config['tables'][$table]['fields'][$field]['exists'] = $fieldExists;
            }
        }
        return $config;
    }

    private function parseFields($fieldsSql)
    {
        preg_match_all('/`(\w+)`/', $fieldsSql, $matches);
        return $matches[1];
    }

    public function updateConfigFromPost(&$config, $postFields)
    {
        $sortedTables = [];
        $processedTables = [];

        $processTable = function ($tableName) use (&$sortedTables, &$processedTables, &$config, &$postFields, &$processTable) {
            if (isset($processedTables[$tableName])) {
                return;
            }

            if (isset($sortedTables[$tableName])) {
                $processedTables[$tableName] = true;
                return;
            }

            if (!isset($postFields[$tableName])) {
                $processedTables[$tableName] = true;
                return;
            }

            $tableData = $config['tables'][$tableName];

            foreach ($tableData['fields'] as $field => &$fieldData) {
                if (isset($postFields[$tableName][$field])) {
                    $fieldPostData = $postFields[$tableName][$field];
                    if (isset($fieldPostData['import'])) {
                        $fieldData['import'] = true;
                    } else {
                        $fieldData['import'] = false;
                        $fieldData['relation'] = null;
                    }

                    if (isset($fieldPostData['staticvalue'])) {
                        $fieldData['staticvalue'] = $this->sanitize($fieldPostData['staticvalue']);
                    }

                    if (isset($fieldPostData['relation'])) {
                        $fieldData['relation'] = $fieldPostData['relation'];
                        if ($tableName == $fieldPostData['relation']) {
                            // Do nothing
                        } else {
                            $processTable($fieldData['relation']);
                        }
                    }
                } else {
                    $fieldData['import'] = false;
                    $fieldData['relation'] = null;
                    $fieldData['staticvalue'] = null;
                }
            }

            $sortedTables[$tableName] = $tableData;
            $processedTables[$tableName] = true;
        };

        foreach ($config['tables'] as $tableName => $tableData) {
            $processTable($tableName);
        }

        $config['tables'] = $sortedTables;

        $this->saveImportConfig($config);
    }

    public function displayImportconfig($importConfig)
    {
        if ($importConfig == null) {
            $importConfig = $this->importConfig;
        }

        echo '<div class="outer-form"><h2>Configuration</h2><ul><li class="green">Green font color: table or field exists in database</li><li class="red">Red font color: table or field not in database</li></ul><form method="POST" action="' . $this->config['base_url'] . '/api/save_config">';
        foreach ($importConfig['tables'] as $table => $tableData) {

            echo '<table class="data-table"><tr><th colspan="4"><h3 style="color:' . ($tableData['exists'] ? 'green' : 'red') . ';">' . $table . '</h3></th></tr><tr><th>Import</th><th>Fieldname</th><th>Relation</th><th>Static value</th></tr>';
            foreach ($tableData['fields'] as $field => $fieldData) {

                $staticValue = '';
                if (isset($fieldData['staticvalue'])) {
                    $staticValue = $fieldData['staticvalue'];
                }
                echo '<tr>';
                echo '<td><input type="checkbox" name="fields[' . $table . '][' . $field . '][import]"' . ($fieldData['import'] ? ' checked' : '') . '></td>';
                echo '<td><span style="color:' . ($fieldData['exists'] ? 'green' : 'red') . ';">' . $field . '</span></td>';
                echo '<td><select name="fields[' . $table . '][' . $field . '][relation]">';
                echo '<option value="">Keine</option>';
                foreach ($importConfig['tables'] as $otherTable => $otherTableData) {
                    if (isset($otherTableData['fields']['uid'])) {
                        $selected = isset($fieldData['relation']) && $fieldData['relation'] === $otherTable ? ' selected' : '';
                        echo '<option value="' . $otherTable . '"' . $selected . '>' . $otherTable . '</option>';
                    }
                }
                echo '</select></td>';
                echo '<td><input type="text" name="fields[' . $table . '][' . $field . '][staticvalue]" value="' . $staticValue . '" /></td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '<button type="submit" class="btn-left" name="save_config">Save configuration</button>';
        echo '<button type="submit" class="btn-right btn-primary" name="import">Import tables and datasets</button><div class="spacer"></div>';
        echo '</form></div>';
    }

    public function saveImportConfig($newImportConfig)
    {
        $this->importConfig = array_merge($this->importConfig, $newImportConfig);
        file_put_contents(__DIR__ . '/../config/importconfig.json', json_encode($this->importConfig, JSON_PRETTY_PRINT));
        echo '<p class="info">Configuration was saved successfully</p>';
    }

    public function displayUploadForm()
    {
        echo '<div class="upload-div"><h2>Databasedump Upload</h2><p>Hint: Existent configuration file will be overwritten by uploading new SQL file.</p><form method="POST" enctype="multipart/form-data" action="' . $this->config['base_url'] . '/api/upload">';
        echo '<input type="file" name="sql_file">';
        echo '<button type="submit">Upload SQL file</button>';
        echo '</form></div>';
    }

    public function displayHeadline()
    {
        $styles = '
        <style>
        body {
            margin:0;
            padding:40px;
            font-family:arial,sans-serif;
            font-size:15px;
        }
        .green {
            color:green;
        }
        .red {
            color:red;
        }
        .outer-form {
             width:800px;
        }
        .data-table {
            border:1px solid #dedede;
            padding:30px;
            background-color:#efefef;
            min-width: 800px;
            margin-bottom:20px;
        }
        .data-table td {
            text-align:left;
            vertical-align:top;
            padding:5px 10px 0 5px;
        }
        .data-table th {
            text-align:left;
            padding:10px;
            
        }
        button, .btn {
            background-color:#7caf23;
            border:0;
            padding:10px 20px;
            color:#ffffff;
            cursor:pointer;
            text-decoration:none;
        }
        .btn-left {
            float:left;
        }
        .btn-right {
            float:right;
        }
        .spacer {
            clear:both;
        }
        .btn-primary {
            background-color:#f37318;
        }
        .upload-div {
            border:1px solid #dedede;
            background-color:#efefef;
            width: 800px;
            margin-top:60px;
            padding:30px;
            box-sizing:border-box;
        }
        .info {
            color:red;
            font-style:italic;
        }
        </style>
        ';
        echo '<h1>Databasedump Import</h1>' . $styles;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getImportConfig()
    {
        return $this->importConfig;
    }

    public function sanitize($value)
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value);
        return $value;
    }
}
