<?php
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../controllers/ConfigController.php';
require_once __DIR__ . '/../controllers/ImportController.php';

$config = require __DIR__ . '/../config/config.php';
if (file_exists(__DIR__ . '/../config/config_local.php')) {
    $configLocal = require __DIR__ . '/../config/config_local.php';
    if (isset($configLocal)) {
        $config = array_merge($config, $configLocal);
    }
}

$database = new Database($config);
$db = $database->getConnection();

$configController = new ConfigController($db);
$importController = new ImportController($db);

$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
$requestMethod = $_SERVER['REQUEST_METHOD'];

switch ($requestUri) {
    case $config['base_url'] . '/api/upload':
        if ($requestMethod === 'POST' && isset($_FILES['sql_file'])) {
            $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
            $configData = $configController->generateConfig($sql);
            $configData['import_file'] = [
                'path' => $config['import_path'],
                'filename' => $_FILES['sql_file']['name']
            ];
            $configController->saveImportConfig($configData);

            $importFilePath = $config['import_path'] . '/' . $_FILES['sql_file']['name'];
            move_uploaded_file($_FILES['sql_file']['tmp_name'], $importFilePath);

            session_start();
            $_SESSION['config'] = $configData; // Store config in session
            $configController->displayHeadline();
            $configController->displayImportconfig($configData);
            $configController->displayUploadForm();
        } else {
            $configController->displayHeadline();
            $configController->displayUploadForm();
        }
        break;

    case $config['base_url'] . '/api/save_config':
        if ($requestMethod === 'POST') {
            session_start();
            $configData = $_SESSION['config'] ?? null;
            if (!$configData) {
                $configData = $configController->getImportConfig();
            }
            if ($configData) {
                $configData = $configController->updateTableAndFieldStatus($configData);
                $configController->updateConfigFromPost($configData, $_POST['fields']);
                $_SESSION['config'] = $configData;
                if (isset($_POST['import'])) {
                    $configController->displayHeadline();
                    $importController->importData($configData);
                } else {
                    $configController->displayHeadline();
                    $configController->displayImportconfig($configData);
                    $configController->displayUploadForm();
                }
            } else {
                echo "Error: Configuration not found.";
            }
        } else {
            header('Location: ' . $config['base_url']);
        }
        break;

    default:
        $importConfigPath = __DIR__ . '/../config/importconfig.json';
        $configController->displayHeadline();
        if (file_exists($importConfigPath)) {
            $configController->displayImportconfig(null);
        }
        $configController->displayUploadForm();
        break;
}
