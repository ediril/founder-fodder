<?php

const MAX_COMMENTS_TO_FETCH = 2500;
const MAX_FETCH = 200;                  // 20 pages worth
const MAX_SUMMARIZE = 30;               // 3 pages worth
const MIN_STORY_LENGTH_FOR_SUMMARY = 200;

const FETCHER_VERSION = "0.0706.001";
const SUMMARIZER_VERSION = "0.0701.001";

const FETCHER_LOCK_FILE_NAME = 'ff_fetch.lock';
const SUMMARIZER_LOCK_FILE_NAME = 'ff_summarize.lock';
const DB_PATH = "stories.db";
const STORIES_PER_PAGE = 10;
const STORY_TYPES = [
    "top" => 1,
    "new" => 2,
    "best" => 3,
    "active" => 4,
];

// Mixed stories configuration - how many stories to intersperse
const INTERSPERSE_INTERVAL = 3;     // Number of active stories before interspersing
const INTERSPERSE_TOP_COUNT = 2;    // Number of "top" stories to add when interspersing
const INTERSPERSE_BEST_COUNT = 1;   // Number of "best" stories to add when interspersing
const INTERSPERSE_NEW_COUNT = 1;    // Number of "new" stories to add when interspersing
const INTERSPERSE_POOL_SIZE = 25;   // Number of stories to fetch from each type for the pool

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

function fetchUrl(string $url, int $timeout = 10, array $headers = [], bool $headersOnly = false, int $maxSize = 10 * 1024 * 1024): string|array|false
{
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];
    
    $allHeaders = array_merge($defaultHeaders, $headers);
    
    $context = stream_context_create([
        'http' => [
            'method' => $headersOnly ? 'HEAD' : 'GET',
            'header' => implode("\r\n", $allHeaders),
            'timeout' => $timeout
        ]
    ]);

    dispatch_signal();

    if ($headersOnly) {
        return @get_headers($url, true, $context);
    } else {
        // Prefer cURL over file_get_contents to avoid internal buffering overhead.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT      => $defaultHeaders[0],
                CURLOPT_HTTPHEADER     => $allHeaders,
                CURLOPT_MAXFILESIZE    => $maxSize,  // Limit download size
            ]);

            $content = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            // Check if download was aborted due to size
            if ($error && strpos($error, 'exceeds file size limit') !== false) {
                logMessage("Download aborted for {$url} - content too large");
                return false;
            }
            
        } else {
            // Fallback if cURL is not available
            $content = @file_get_contents($url, false, $context, 0, $maxSize);
        }

        // Return false if content is false, empty, or just whitespace
        if ($content === false || empty(trim((string)$content))) {
            return false;
        }
        return $content;
    }
}

// Stream-download a URL directly to a local file to avoid holding the whole
// response in memory. Returns true on success, false on failure.
function downloadUrlToFile(string $url, string $destPath, int $timeout = 15, array $headers = []): bool
{
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ];

    $allHeaders = array_merge($defaultHeaders, $headers);

    // Open destination file for writing (binary-safe)
    $fp = fopen($destPath, 'wb');
    if ($fp === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,         // write directly to file
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_CONNECTTIMEOUT  => $timeout,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_FAILONERROR     => true,        // treat HTTP >= 400 as error
        CURLOPT_HTTPHEADER      => $allHeaders,
        CURLOPT_USERAGENT       => $allHeaders[0] ?? '',
    ]);

    dispatch_signal();

    $success = curl_exec($ch) !== false;
    curl_close($ch);
    fclose($fp);

    if (!$success) {
        // Remove partial file if download failed
        if (file_exists($destPath)) {
            unlink($destPath);
        }
    }

    return $success;
}

/**
 * Create a DOMDocument configured for proper UTF-8 HTML parsing
 * This prevents encoding issues when parsing HTML content
 */
function createUtf8DOMDocument(string $html): DOMDocument
{
    // Properly handle UTF-8 encoding by adding UTF-8 BOM and meta tag
    $html = "\xEF\xBB\xBF" . '<meta charset="UTF-8">' . $html;
    
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->encoding = 'UTF-8';
    
    // Suppress warnings for malformed HTML
    libxml_use_internal_errors(true);
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    return $dom;
}