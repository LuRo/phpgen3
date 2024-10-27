<?php

// Read the config.json
$configFilePath = './config.json';
$config = json_decode(file_get_contents($configFilePath), true);

// Read the outputconfig.json to determine output paths
$outputConfig = json_decode(file_get_contents('./outputconfig.json'), true);

// Ensure the base path ends with a slash
$basePath = rtrim($outputConfig['basePath'], '/') . '/';

// Build paths based on output configuration
$modelsPath = $basePath . rtrim($outputConfig['modelsDirectory'], '/') . '/';
$controllersPath = $basePath . rtrim($outputConfig['controllersDirectory'], '/') . '/';
$viewsPath = $basePath . rtrim($outputConfig['viewsDirectory'], '/') . '/';
$routerPath = $basePath . $outputConfig['routerFile'];
$indexFilePath = $basePath . $outputConfig['indexFileName'];
$databaseFilePath = $outputConfig['databaseCreationFileName'];
$targetDatabasePath = $basePath . $outputConfig['targetDatabaseFileName'];

// Include the database creation function
include 'create_database.php';

// Function to ensure directory exists
function ensureDirectory($path) {
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
        echo "Directory created: {$path}\n";
    }
}

// Ensure necessary directories exist
ensureDirectory($modelsPath);
ensureDirectory($controllersPath);
ensureDirectory($viewsPath);
ensureDirectory(dirname($targetDatabasePath));

// Rename existing database if it exists
if (file_exists($databaseFilePath)) {
    $timestamp = date('Ymd_His');
    $newDatabaseFilePath = $databaseFilePath . "_backup_" . $timestamp;
    rename($databaseFilePath, $newDatabaseFilePath);
    echo "Existing database renamed to: {$newDatabaseFilePath}\n";
}

// Create or replace the SQLite database
createDatabase($config);

// Copy the newly created database to the target 'app' folder
if (file_exists($databaseFilePath)) {
    copy($databaseFilePath, $targetDatabasePath);
    echo "Database copied to: {$targetDatabasePath}\n";
} else {
	echo "Database not found in {$databaseFilePath}\n";
}
	

// ======  Function to create models
function createModels($config, $modelsPath) {
    foreach ($config['tables'] as $tableName => $tableData) {
        $modelName = ucfirst($tableName);  // Ensure model name starts with an uppercase letter
        $modelFile = "{$modelsPath}{$modelName}.php";

        $fields = array_keys($tableData['fields']);
        $keyField = '';
        foreach ($tableData['fields'] as $field => $attributes) {
            if ($attributes['keyField'] === 'yes') {
                $keyField = $field;
                break;
            }
        }

        // Add created_at and updated_at to fields if not present
        if (!in_array('created_at', $fields)) {
            $fields[] = 'created_at';
        }
        if (!in_array('updated_at', $fields)) {
            $fields[] = 'updated_at';
        }

        $modelContent = <<<PHP
<?php

class {$modelName} {
    private \$db;

    public function __construct() {
        \$this->db = new PDO('sqlite:./database.sqlite');
    }

    public function getAll() {
        \$stmt = \$this->db->query("SELECT * FROM {$tableName}");
        return \$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

PHP;

        // getById method
        if ($keyField) {
            $modelContent .= <<<PHP

    public function getById(\$id) {
        \$stmt = \$this->db->prepare("SELECT * FROM {$tableName} WHERE {$keyField} = :id");
        \$stmt->execute(['id' => \$id]);
        return \$stmt->fetch(PDO::FETCH_ASSOC);
    }

PHP;
        }

        // create method (includes setting created_at and updated_at)
        $columns = implode(", ", $fields);
        $placeholders = ":" . implode(", :", $fields);
        $modelContent .= <<<PHP

    public function create(\$data) {
        \$data['created_at'] = date('Y-m-d H:i:s');
        \$data['updated_at'] = date('Y-m-d H:i:s');
        \$stmt = \$this->db->prepare("INSERT INTO {$tableName} ({$columns}) VALUES ({$placeholders})");
        \$stmt->execute(\$data);
    }

PHP;

        // update method (includes setting updated_at)
if ($keyField) {
    // Filter out the primary key field and 'created_at' from fields to be updated
    $updateFields = array_filter($fields, fn($f) => $f !== $keyField && $f !== 'created_at');

    // Create the update string with placeholders for each field
    $updateFieldsString = implode(", ", array_map(fn($f) => "{$f} = :{$f}", $updateFields));


    $modelContent .= <<<PHP

    public function update(\$id, \$data) {
        // Set the updated_at field
        \$data['updated_at'] = date('Y-m-d H:i:s');

        // Remove created_at from data to avoid binding it in the update query
        unset(\$data['$keyField']);
        unset(\$data['created_at']);
        unset(\$data['_method']);

        // Add the ID to data for the WHERE clause
        \$data['id'] = \$id;

        // Prepare the SQL statement
        \$stmt = \$this->db->prepare("UPDATE {$tableName} SET {$updateFieldsString} WHERE {$keyField} = :id");

        // Execute the statement
        \$stmt->execute(\$data);
    }

PHP;
    }

        // delete method
        if ($keyField) {
            $modelContent .= <<<PHP

    public function delete(\$id) {
        \$stmt = \$this->db->prepare("DELETE FROM {$tableName} WHERE {$keyField} = :id");
        \$stmt->execute(['id' => \$id]);
    }

PHP;
        }

        $modelContent .= "}\n";

        // Create the model file
        file_put_contents($modelFile, $modelContent);
        echo "Model created: {$modelFile}\n";
    }
}

// ====    Function to create views
function createViews($config, $viewsPath) {
    foreach ($config['tables'] as $tableName => $tableData) {
        $tableViewPath = $viewsPath . $tableName . '/';
        ensureDirectory($tableViewPath);

        // Get the primary key field for the table
        $keyField = '';
        foreach ($tableData['fields'] as $field => $attributes) {
            if (isset($attributes['keyField']) && $attributes['keyField'] === 'yes') {
                $keyField = $field;
                break;
            }
        }

        // Create list view
        $listViewFile = $tableViewPath . 'list.php';
        $listViewContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>List of {$tableName}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <div class="container">
        <h1 class="my-4">List of {$tableName}</h1>
        <button type="button" class="btn btn-primary mb-4" data-bs-toggle="modal" data-bs-target="#createModal">Create New</button>
        <table class="table table-bordered">
            <thead>
                <tr>
HTML;
        foreach ($tableData['fields'] as $field => $attributes) {
            $listViewContent .= "<th>{$field}</th>\n";
        }
        $listViewContent .= <<<HTML
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (\$records as \$record): ?>
                <tr>
HTML;
        foreach ($tableData['fields'] as $field => $attributes) {
            $listViewContent .= "<td><?= \$record['{$field}'] ?></td>\n";
        }
        if ($keyField) {
            $listViewContent .= <<<HTML
                    <td>
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editModal_<?= \$record['{$keyField}'] ?>">Edit</button>
                        <form method="POST" action="./router.php?r={$tableName}/<?= \$record['{$keyField}'] ?>" style="display:inline;">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal_<?= \$record['{$keyField}'] ?>" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editModalLabel">Edit {$tableName}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form method="POST" action="./router.php?r={$tableName}/<?= \$record['{$keyField}'] ?>">
                                    <input type="hidden" name="_method" value="PUT">
HTML;
            foreach ($tableData['fields'] as $field => $attributes) {
                if (isset($attributes['showInForm']['displayField']) && $attributes['showInForm']['displayField'] === 'show') {
                    if (isset($attributes['lookupDefinition'])) {
                        $listViewContent .= <<<HTML
                                    <div class="mb-3">
                                        <label for="edit_{$field}_<?= \$record['{$keyField}'] ?>" class="form-label">{$field}:</label>
                                        <select class="form-control" name="{$field}" id="edit_{$field}_<?= \$record['{$keyField}'] ?>">
HTML;
                        if (isset($attributes['lookupDefinition']['values'])) {
                            foreach ($attributes['lookupDefinition']['values'] as $value) {
                                $listViewContent .= <<<HTML
                                            <option value="{$value}" <?= \$record['{$field}'] == '{$value}' ? 'selected' : '' ?>>{$value}</option>
HTML;
                            }
                        }
                        $listViewContent .= <<<HTML
                                        </select>
                                    </div>
HTML;
                    } else {
                        $listViewContent .= <<<HTML
                                    <div class="mb-3">
                                        <label for="edit_{$field}_<?= \$record['{$keyField}'] ?>" class="form-label">{$field}:</label>
                                        <input type="text" class="form-control" name="{$field}" id="edit_{$field}_<?= \$record['{$keyField}'] ?>" value="<?= \$record['{$field}'] ?>">
                                    </div>
HTML;
                    }
                }
            }
            $listViewContent .= <<<HTML
                                    <button type="submit" class="btn btn-primary">Save changes</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
HTML;
        }
        $listViewContent .= <<<HTML
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createModalLabel">Create {$tableName}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="./router.php?r={$tableName}/store">
HTML;
        foreach ($tableData['fields'] as $field => $attributes) {
            if (isset($attributes['showInForm']['displayField']) && $attributes['showInForm']['displayField'] === 'show') {
                if (isset($attributes['lookupDefinition'])) {
                    // Render select dropdown for lookup fields
                    $listViewContent .= <<<HTML
                        <div class="mb-3">
                            <label for="{$field}" class="form-label">{$field}:</label>
                            <select class="form-control" name="{$field}" id="{$field}">
HTML;
                    if (isset($attributes['lookupDefinition']['values'])) {
                        foreach ($attributes['lookupDefinition']['values'] as $value) {
                            $listViewContent .= <<<HTML
                                <option value="{$value}">{$value}</option>
HTML;
                        }
                    }
                    $listViewContent .= <<<HTML
                            </select>
                        </div>
HTML;
                } else {
                    // Render normal input field for other fields
                    $listViewContent .= <<<HTML
                        <div class="mb-3">
                            <label for="{$field}" class="form-label">{$field}:</label>
                            <input type="text" class="form-control" name="{$field}" id="{$field}">
                        </div>
HTML;
                }
            }
        }
        $listViewContent .= <<<HTML
                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
HTML;
        file_put_contents($listViewFile, $listViewContent);
        echo "List view created: {$listViewFile}\n";
    }
}

// ==      Function to create controllers with all CRUD actions and sub-table handling
function createControllers($config, $controllersPath) {
    foreach ($config['tables'] as $tableName => $tableData) {
        $controllerName = ucfirst($tableName) . "Controller";  // Ensure controller name starts with an uppercase letter
        $controllerFile = "{$controllersPath}{$controllerName}.php";

        $modelName = ucfirst($tableName);

        $controllerContent = <<<PHP
<?php

class {$controllerName} {

    public function __construct() {
        include_once './models/{$modelName}.php';
        //includeLookupModels(); // Load lookup models automatically
    }

    // List all records
    public function index() {
        \$model = new {$modelName}();
        \$records = \$model->getAll();
        include "./views/{$tableName}/list.php";
    }

    // Show form for creating a new record
    public function create() {
        \$lookupValues = [];
        \$config = json_decode(file_get_contents('./config.json'), true);
        \$fields = \$config['tables']['{$tableName}']['fields'];
        foreach (\$fields as \$fieldName => \$fieldData) {
            if (isset(\$fieldData['lookupDefinition'])) {
                \$lookupDefinition = \$fieldData['lookupDefinition'];
                if (isset(\$lookupDefinition['values'])) {
                    \$lookupValues[\$fieldName] = \$lookupDefinition['values'];
                } elseif (isset(\$lookupDefinition['lookupTable'])) {
                    \$lookupModel = new {$modelName}();
                    \$lookupValues[\$fieldName] = \$lookupModel->getAll();
                }
            }
        }
        include "./views/{$tableName}/create.php";
    }

    // Handle the creation of a new record
    public function store(\$data) {
        \$data = \$_POST;
        \$model = new {$modelName}();
        \$model->create(\$data);
        header("Location: ./router.php?r={$tableName}");
    }

    // Show form for editing an existing record, including sub-tables
    public function edit(\$id) {
        \$model = new {$modelName}();
        \$record = \$model->getById(\$id);
        \$lookupValues = [];
        \$config = json_decode(file_get_contents('./config.json'), true);
        \$fields = \$config['tables']['{$tableName}']['fields'];
        foreach (\$fields as \$fieldName => \$fieldData) {
            if (isset(\$fieldData['lookupDefinition'])) {
                \$lookupDefinition = \$fieldData['lookupDefinition'];
                if (isset(\$lookupDefinition['values'])) {
                    \$lookupValues[\$fieldName] = \$lookupDefinition['values'];
                } elseif (isset(\$lookupDefinition['lookupTable'])) {
                    \$lookupModel = new {$modelName}();
                    \$lookupValues[\$fieldName] = \$lookupModel->getAll();
                }
            }
        }

        // Handle sub-tables based on the configuration
        if (isset(\$config['tables']['{$tableName}']['subTables'])) {
            foreach (\$config['tables'][$tableName]['subTables'] as \$subTableName => \$subTableConfig) {
                \$editMode = \$subTableConfig['editMode'] ?? 'single'; // Default to single
                if (\$editMode === 'bulk') {
                    // Handle bulk editing for sub-table
                    \$subModel = new \$subTableName();
                    \$subRecords = \$subModel->getAllByForeignKey(\$id);
                    // Add bulk-edit-related logic here
                } else {
                    // Handle single edit logic for sub-table
                }
            }
        }

        include "./views/{$tableName}/edit.php";
    }

    // Handle updating an existing record
    public function update(\$id) {
        \$data = \$_POST;
        \$model = new {$modelName}();
        \$model->update(\$id, \$data);
        header("Location: ./router.php?r={$tableName}");
    }

    // Handle deleting a record
    public function delete(\$id) {
        \$model = new {$modelName}();
        \$model->delete(\$id);
        header("Location: ./router.php?r={$tableName}");
    }
}

PHP;

        // Create the controller file
        file_put_contents($controllerFile, $controllerContent);
        echo "Controller created: {$controllerFile}\n";
    }
}

// Function to create router file
function createRouter($routerPath) {
    $routerCode = <<<PHP
    <?php
    // router.php

    // Autoload function for loading classes
    spl_autoload_register(function (\$className) {
        \$filePath = "controllers/\$className.php";
        if (file_exists(\$filePath)) {
            include \$filePath;
        }
    });

    // Parse the request from the query string
    \$request = isset(\$_GET['r']) ? \$_GET['r'] : '';
    \$requestParts = explode('/', \$request);

    // Determine the table and action based on the HTTP method
    \$tableName = !empty(\$requestParts[0]) ? ucfirst(\$requestParts[0]) : '';
    \$primaryKeyValue = isset(\$requestParts[1]) ? \$requestParts[1] : null;

    // Determine the HTTP method and map to the corresponding action
    if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['_method'])) {
        \$method = strtoupper(\$_POST['_method']);
        if (in_array(\$method, ['PUT', 'DELETE'])) {
            \$_SERVER['REQUEST_METHOD'] = \$method;
        }
    }

    switch (\$_SERVER['REQUEST_METHOD']) {
    case 'GET':
        \$action = \$primaryKeyValue ? 'edit' : 'index';
        break;
    case 'POST':
        \$action = 'store';
        break;
    case 'PUT':
        \$action = 'update';
        break;
    case 'DELETE':
        \$action = 'delete';
        break;
    default:
        http_response_code(405);
        echo 'Method Not Allowed';
        exit;
    }

    // Construct the controller name
    \$controllerName = \$tableName . 'Controller';
    \$controllerFile = "controllers/\$controllerName.php";

    // Check if the controller file exists
    if (file_exists(\$controllerFile)) {
        include \$controllerFile;
        if (class_exists(\$controllerName)) {
            \$controller = new \$controllerName();
            if (method_exists(\$controller, \$action)) {
                // Call the action method with the primary key value if available
                if (\$primaryKeyValue) {
                    \$controller->{\$action}(\$primaryKeyValue);
                } else {
                    \$controller->{\$action}();
                }
            } else {
                // Action not found
                http_response_code(404);
                echo "Error 404: Action '\$action' not found in controller '\$controllerName'.";
            }
        } else {
            // Controller class not found
            http_response_code(404);
            echo "Error 404: Controller class '\$controllerName' not found.";
        }
    } else {
        // Controller file not found
        http_response_code(404);
        echo "Error 404: Controller file '\$controllerFile' not found.";
    }
    PHP;

    file_put_contents($routerPath, $routerCode);
    echo "Router created: {$routerPath}\n";
}

// Function to create index.php file
function createIndexPage($config, $indexFilePath) {
    $tileContent = '';
    foreach ($config['tables'] as $tableName => $tableData) {
        $tileName = ucfirst($tableName);
        $tileContent .= <<<HTML

        <div class="col-md-3 mb-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">{$tileName}</h5>
                    <a href="router.php?r={$tableName}" class="btn btn-primary">View {$tileName}</a>
                </div>
            </div>
        </div>
HTML;
    }

    $indexContent = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Tables Overview</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container">
    <h1 class="mt-5 mb-4">Database Tables Overview</h1>
    <div class="row">
{$tileContent}
    </div>
</body>
</html>
HTML;

    // Write the index file
    file_put_contents($indexFilePath, $indexContent);
    echo "Index page created: {$indexFilePath}\n";
}


// Function to create the config.json file in the app root directory
function copyConfigFileToRoot($configFilePath, $basePath) {
    $destinationPath = $basePath . 'config.json';
    copy($configFilePath, $destinationPath);
    echo "config.json copied to application root: {$destinationPath}\n";
}

// Main script execution
createModels($config, $modelsPath);
createViews($config, $viewsPath);
createControllers($config, $controllersPath);
createRouter($routerPath);
createIndexPage($config, $indexFilePath);
copyConfigFileToRoot($configFilePath, $basePath);

?>
