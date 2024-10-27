<?php
// Create the database using PDO
function createDatabase($config)
{
    $pdo = getPDOConnection();

    foreach ($config['tables'] as $tableName => $table) {
        $fields = [];
        $primaryKey = null;

        foreach ($table['fields'] as $fieldName => $field) {
            if (isset($field['keyField']) && $field['keyField'] === 'yes') {
                if (isset($field['fieldType']) && $field['fieldType'] === 'number') {
                    $fields[] = "$fieldName INTEGER PRIMARY KEY AUTOINCREMENT";
                } else {
                    $fields[] = "$fieldName TEXT PRIMARY KEY";
                }
                $primaryKey = $fieldName;
            } else {
                $fieldType = getFieldType($field['fieldType']);
                $fields[] = "$fieldName $fieldType";
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS $tableName (" . implode(", ", $fields) . ");";
        $pdo->exec($sql);
    }
    echo "Database created successfully.\n<br />";
}


// Get the corresponding SQLite data type for each field type
function getFieldType($type)
{
    switch ($type) {
        case 'number':
            return 'INTEGER';
        case 'text':
            return 'TEXT';
        case 'date':
            return 'DATE';
        default:
            return 'TEXT';
    }
}


   // Establish a PDO connection
        function getPDOConnection() {
            $dsn = 'sqlite:database.sqlite'; // SQLite database path
            try {
                $pdo = new PDO($dsn);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $pdo;
            } catch (PDOException $e) {
                echo 'Connection failed: ' . $e->getMessage();
                exit;
            }
        }