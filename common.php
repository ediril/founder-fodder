<?php

const DB_PATH = "stories.db";
const STORIES_PER_PAGE = 10;
const STORY_TYPES = [
    "top" => 1,
    "new" => 2,
    "best" => 3,
];

const MAX_COMMENTS_TO_FETCH = 3000;
const FETCH_LIMIT = 200;

const FETCHER_LOCK_FILE_NAME = 'ff_fetch.lock';
const SUMMARIZER_LOCK_FILE_NAME = 'ff_summarize.lock';

function acquireLock(string $lockName) {
    $lockFile = __DIR__ . '/' . $lockName;       
    $lockHandle = fopen($lockFile, 'c');
    if (!$lockHandle) {
        throw new Exception("Cannot create lock file: {$lockFile}");
    }

    // Try to get an exclusive lock
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        throw new Exception("Another instance of FETCH is already running");
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

function close_db_connection(?PDO $conn): void {
    if ($conn !== null) {
        $conn = null;
    }
}

function check_if_other_process_running(string $lockName, int $timeout = 60): void {
    $waitTime = 0;
    $first = true;
    do {
        if (file_exists(__DIR__ . '/' . $lockName)) {
            if ($first) {
                $first = false;
                logMessage("Waiting for {$lockName} to stop running");
            }
            $waitTime += 3;
            sleep(3);
            dispatch_signal();
        } else {
            logMessage("OK, {$lockName} stopped");
            break;
        }
        if ($waitTime >= $timeout) {
            throw new Exception("{$lockName} is still running");
        }
    } while(true);
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
