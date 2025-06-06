<?php

// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/common.php';

function create_db($dbPath = DB_PATH): void
{
    echo "Creating new database at: {$dbPath}\n";
    
    // Check if database already exists
    if (file_exists($dbPath)) {
        echo "WARNING: Database file already exists at {$dbPath}\n";
        return;
    }
        
    try {
        $pdo = get_db_connection($dbPath);
        
        echo "Creating stories table...\n";        
        $pdo->exec('
            CREATE TABLE stories (
                id INTEGER PRIMARY KEY,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                domain TEXT NOT NULL,
                comments_url TEXT NOT NULL,
                comments_count INTEGER NOT NULL,
                score INTEGER NOT NULL,
                user TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                fetched_at INTEGER NOT NULL
            )
        ');
        
        echo "Creating ranks table...\n";
        $pdo->exec('
            CREATE TABLE ranks (
                id INTEGER NOT NULL,
                type INTEGER NOT NULL,
                rank INTEGER,
                PRIMARY KEY (id, type),
                FOREIGN KEY (id) REFERENCES stories(id) ON DELETE CASCADE
            )
        ');
        
        echo "Creating database indices...\n";
        $pdo->exec('CREATE INDEX idx_ranks_type_rank ON ranks(type, rank)');
        $pdo->exec('CREATE INDEX idx_story_timestamp ON stories(timestamp)');
        
        echo "Database created successfully!\n";
        
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
    $options = getopt('h', ['db:', 'create', 'integrity', 'help']);
    
    if (isset($options['help'])) {
        echo "Usage: php database.php [options]\n";
        echo "Options:\n";
        echo "  --create        Create a new blank database\n";
        echo "  --check         Check the database for errors\n";
        echo "  --db=PATH       Path to SQLite database (default: " . DB_PATH . ")\n";
        echo "  --help, h       Show this help message\n";
        echo "\nExamples:\n";
        echo "  php database.php --integrity\n";
        echo "  php database.php --create\n";
        echo "  php database.php --create --db=/path/to/custom.db\n";
        exit(0);
    }
    
    $dbPath = $options['db'] ?? DB_PATH;
    
    if (isset($options['create'])) {
        create_db($dbPath);
    } elseif (isset($options['integrity'])) {
        db_integrity_check(get_db_connection($dbPath));
    } else {
        echo "Please specify either --create or --repair. Use --help for more information.\n";
        exit(1);
    }
} 