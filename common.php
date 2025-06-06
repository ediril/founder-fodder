<?php

const MAX_COMMENTS_TO_FETCH = 2500;
const MAX_FETCH = 250;                  // 25 pages fetched
const MAX_SUMMARIZE = 50;               // 5 pages summarized
const MIN_STORY_LENGTH_FOR_SUMMARY = 250;

const FETCHER_VERSION = "0.0606.003";
const SUMMARIZER_VERSION = "0.0606.005";

const FETCHER_LOCK_FILE_NAME = 'ff_fetch.lock';
const SUMMARIZER_LOCK_FILE_NAME = 'ff_summarize.lock';
const DB_PATH = "stories.db";
const STORIES_PER_PAGE = 10;
const STORY_TYPES = [
    "top" => 1,
    "new" => 2,
    "best" => 3,
];

function acquireLock(string $lockName) {
    $lockFile = __DIR__ . '/' . $lockName;       
    $lockHandle = fopen($lockFile, 'c');
    if (!$lockHandle) {
        logMessage("Cannot create lock file: {$lockFile}");
        exit(1);
    }

    // Try to get an exclusive lock
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        logMessage("Another instance of FETCH is already running");
        exit(1);
    }

    logMessage("Acquired process lock (PID: " . getmypid() . ")");

    return $lockHandle;
}

function releaseLock($lockHandle, string $lockFile): void {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        
        // Remove the lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        logMessage("Released process lock");
    }
}

function handleSignal(int $signal): void {
    logMessage("Received signal {$signal}. Cleaning up and exiting...");
    exit(0);
}

// Handle signals (CTRL+C, etc.)
function dispatch_signal(): void {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

declare(ticks = 1);
function setup_signal_handlers(): void {
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, 'handleSignal');
        pcntl_signal(SIGINT, 'handleSignal');
    }
}

function get_db_connection($dbPath = DB_PATH): PDO
{
    if (!file_exists($dbPath)) {
        throw new Exception("Database file not found: {$dbPath}");
    }

    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Configure SQLite for better reliability and performance
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA temp_store = MEMORY');
    $pdo->exec('PRAGMA mmap_size = 268435456'); // 256MB
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function db_integrity_check($pdo): bool {
    // Test basic query
    $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
    $tableCount = $stmt->fetchColumn();
    echo "Connection OK (found {$tableCount} tables)\n";
    
    // Check integrity
    echo "Checking DB integrity... ";
    $stmt = $pdo->query('PRAGMA integrity_check');
    $result = $stmt->fetchColumn();
    
    if ($result === 'ok') {
        echo "HEALTHY\n";
        return true;
    }
    
    echo "FAILED: {$result}\n";
    return false;
}

function close_db_connection(?PDO $conn): void {
    if ($conn !== null) {
        $conn = null;
    }
}

function exit_if_other_process_running(string $lockName, int $timeout = 60 * 2): void {
    if (file_exists(__DIR__ . '/' . $lockName)) {
        logMessage("{$lockName} is still running");
        exit(1);
    }
}

function debugLog($message) {
    $logEnabled = false; // Set to true when debugging
    if ($logEnabled) {
        file_put_contents('debug.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }
}

function logMessage(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}
