<?php

// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/defines.php';

function check($dbPath = DB_PATH): void
{
        // Try to connect to the database
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "Testing database connection... ";
        
        // Test basic query
        $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
        $tableCount = $stmt->fetchColumn();
        echo "OK (found {$tableCount} tables)\n";
        
        // Check integrity
        echo "Checking database integrity... ";
        $stmt = $pdo->query('PRAGMA integrity_check');
        $result = $stmt->fetchColumn();
        
        if ($result === 'ok') {
            echo "OK - Database appears to be healthy\n";
            return;
        } else {
            echo "FAILED: {$result}\n";
        }
        

}

function create_db($dbPath = DB_PATH): void
{
    echo "Creating new database at: {$dbPath}\n";
    
    // Check if database already exists
    if (file_exists($dbPath)) {
        echo "WARNING: Database file already exists at {$dbPath}\n";
        return;
    }
        
    try {
        // Create new database connection
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "Configuring database settings...\n";
        
        // Configure the database with optimal settings
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA foreign_keys = ON');
        
        echo "Creating stories table...\n";
        
        // Create the stories table
        $pdo->exec('
            CREATE TABLE stories (
                id INTEGER PRIMARY KEY,
                title TEXT,
                url TEXT,
                domain TEXT,
                comments_url TEXT,
                comments_count INTEGER,
                score INTEGER,
                user TEXT,
                timestamp INTEGER,
                fetched_at INTEGER
            )
        ');
        
        echo "Creating ranks table...\n";
        
        // Create the ranks table with composite primary key and foreign key constraint
        $pdo->exec('
            CREATE TABLE ranks (
                id INTEGER,
                type INTEGER,
                rank INTEGER,
                PRIMARY KEY (id, type),
                FOREIGN KEY (id) REFERENCES stories(id) ON DELETE CASCADE
            )
        ');
        
        echo "Creating database indices...\n";
        
        // Create indices for better performance
        $pdo->exec('CREATE INDEX idx_ranks_type_rank ON ranks(type, rank)');
        $pdo->exec('CREATE INDEX idx_story_timestamp ON stories(timestamp)');
        
        echo "Database created successfully!\n";
        echo "Location: {$dbPath}\n";
        
    } catch (PDOException $e) {
        echo "Error creating database: " . $e->getMessage() . "\n";
        
        // Clean up if there was an error
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
        
        throw $e;
    }
}

// Command line interface
if (php_sapi_name() === 'cli') {
    $options = getopt('h', ['db:', 'create', 'check', 'help']);
    
    if (isset($options['help'])) {
        echo "Usage: php database.php [options]\n";
        echo "Options:\n";
        echo "  --create        Create a new blank database\n";
        echo "  --check         Check the database for errors\n";
        echo "  --db=PATH       Path to SQLite database (default: " . DB_PATH . ")\n";
        echo "  --help, h       Show this help message\n";
        echo "\nExamples:\n";
        echo "  php database.php --check\n";
        echo "  php database.php --create\n";
        echo "  php database.php --create --db=/path/to/custom.db\n";
        exit(0);
    }
    
    $db = $options['db'] ?? DB_PATH;
    
    if (isset($options['create'])) {
        create_db($db);
    } elseif (isset($options['check'])) {
        check($db);
    } else {
        echo "Please specify either --create or --repair. Use --help for more information.\n";
        exit(1);
    }
} 