<?php
declare(strict_types=1);

require_once 'banalytics/banalytics.php';
record_visit();

// Database path
const DB_PATH = "hn_stories.db";
const STORIES_PER_PAGE = 10;

/**
 * Connect to the SQLite database
 */
function get_db_connection(): PDO {
    $conn = new PDO("sqlite:" . DB_PATH);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $conn;
}

/**
 * Format timestamp as 'time ago'
 */
function format_time_ago(int $timestamp): string {
    $seconds = (int)(time() - $timestamp);
    
    if ($seconds < 60) {
        return "{$seconds} seconds ago";
    } elseif ($seconds < 3600) {
        $minutes = (int)($seconds / 60);
        return "{$minutes} minute" . ($minutes > 1 ? 's' : '') . " ago";
    } elseif ($seconds < 86400) {
        $hours = (int)($seconds / 3600);
        return "{$hours} hour" . ($hours > 1 ? 's' : '') . " ago";
    } else {
        $days = (int)($seconds / 86400);
        return "{$days} day" . ($days > 1 ? 's' : '') . " ago";
    }
}

/**
 * API endpoint to get stories
 */
function get_stories(): array {
    try {
        // Parse request parameters
        $story_type = $_GET['type'] ?? 'top';
        $page = (int)($_GET['page'] ?? 1);
        $per_page = (int)($_GET['per_page'] ?? 30);
        
        // Log debugging info
        debugLog("get_stories called with type: $story_type, page: $page, per_page: $per_page");
        
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        try {
            $conn = get_db_connection();
            debugLog("Database connection established");
        } catch (Exception $e) {
            debugLog("Database connection failed: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Database connection failed: " . $e->getMessage()];
        }
        
        // Check if the database file exists
        if (!file_exists(DB_PATH)) {
            debugLog("Database file not found: " . DB_PATH);
            return ["error" => "Database file not found"];
        }
        
        // Get stories by type, ordered by ranking
        try {
            $stmt = $conn->prepare('
                SELECT id, title, url, domain, comments_url, comments_count, 
                       score, user, timestamp, type, ranking
                FROM stories
                WHERE type = ?
                ORDER BY ranking
                LIMIT ? OFFSET ?
            ');
            $stmt->execute([$story_type, $per_page, $offset]);
            
            // Convert to array of associative arrays
            $stories = [];
            while ($row = $stmt->fetch()) {
                // Add additional fields
                $row['time_ago'] = format_time_ago((int)$row['timestamp']);
                $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
                
                // Generate image URL based on story ID if the image exists
                $img_path = "static/img/stories/{$row['id']}.jpg";
                
                // Log file existence check
                debugLog("Checking for image: $img_path, exists: " . (file_exists($img_path) ? "yes" : "no"));
                
                if (file_exists($img_path)) {
                    $row['image_url'] = "/{$img_path}";
                } else {
                    // Don't include image_url if file doesn't exist
                    $row['image_url'] = "";
                }
                
                $stories[] = $row;
            }
            
            debugLog("Retrieved " . count($stories) . " stories from database");
            return $stories;
        } catch (Exception $e) {
            debugLog("Database query failed: " . $e->getMessage());
            http_response_code(500);
            return ["error" => "Database query failed: " . $e->getMessage()];
        }
    } catch (Exception $e) {
        debugLog("Unexpected error in get_stories: " . $e->getMessage());
        http_response_code(500);
        return ["error" => "Unexpected error: " . $e->getMessage()];
    }
}

/**
 * Get stories for a specific page with pagination
 */
function get_page_stories(string $story_type = 'top', int $page = 1, int $per_page = STORIES_PER_PAGE): array {
    try {
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        debugLog("Getting page stories: type=$story_type, page=$page, per_page=$per_page, offset=$offset");
        
        $conn = get_db_connection();
        
        // Get total count for pagination
        $stmt = $conn->prepare('SELECT COUNT(*) as total FROM stories WHERE type = ?');
        $stmt->execute([$story_type]);
        $result = $stmt->fetch();
        $total = (int)($result['total'] ?? 0);
        
        // Get stories by type, ordered by ranking
        $stmt = $conn->prepare('
            SELECT id, title, url, domain, comments_url, comments_count, 
                   score, user, timestamp, type, ranking
            FROM stories
            WHERE type = ?
            ORDER BY ranking
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$story_type, $per_page, $offset]);
        
        // Convert to array of associative arrays
        $stories = [];
        while ($row = $stmt->fetch()) {
            // Add additional fields
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            
            // Generate image URL based on story ID if the image exists
            $img_path = "static/img/stories/{$row['id']}.jpg";
            if (file_exists($img_path)) {
                $row['image_url'] = "/{$img_path}";
            } else {
                // Don't include image_url if file doesn't exist
                $row['image_url'] = "";
            }
            
            $stories[] = $row;
        }
        
        // Add pagination info
        $pagination = [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
            'has_more' => ($page * $per_page) < $total,
            'next_page' => ($page * $per_page) < $total ? $page + 1 : null,
        ];
        
        return [
            'stories' => $stories,
            'pagination' => $pagination,
            'type' => $story_type
        ];
    } catch (Exception $e) {
        debugLog("Error getting page stories: " . $e->getMessage());
        return [
            'stories' => [],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total' => 0,
                'total_pages' => 0,
                'has_more' => false,
                'next_page' => null
            ],
            'type' => $story_type,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get statistics about stored stories
 */
function get_stats(): array {
    try {
        $conn = get_db_connection();
        
        // Count stories by type
        $stmt = $conn->query('
            SELECT type, COUNT(*) as count
            FROM stories
            GROUP BY type
        ');
        
        $type_counts = [];
        while ($row = $stmt->fetch()) {
            $type_counts[$row['type']] = (int)$row['count'];
        }
        
        // Get most recent fetch time
        $stmt = $conn->query('SELECT MAX(fetched_at) as last_fetch FROM stories');
        $result = $stmt->fetch();
        $last_fetch = $result['last_fetch'];
        
        // Format time ago
        $last_fetch_ago = $last_fetch ? format_time_ago((int)$last_fetch) : 'never';
        
        return [
            'counts' => $type_counts,
            'total' => array_sum($type_counts),
            'last_fetch' => $last_fetch,
            'last_fetch_ago' => $last_fetch_ago
        ];
    } catch (Exception $e) {
        http_response_code(500);
        return ["error" => $e->getMessage()];
    }
}

/**
 * Check if summary files exist for a story
 */
function check_summary_files(string $story_id): array {
    $story_summary_path = "static/content/{$story_id}-story-summary.md";
    $comments_summary_path = "static/content/{$story_id}-comments-summary.md";
    
    $has_story_summary = file_exists($story_summary_path);
    $has_comments_summary = file_exists($comments_summary_path);
    
    return [
        'has_story_summary' => $has_story_summary,
        'has_comments_summary' => $has_comments_summary
    ];
}

// Debug function to log requests - remove in production
function debugLog($message) {
    $logEnabled = false; // Set to true when debugging
    if ($logEnabled) {
        file_put_contents('debug.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }
}

// Get the base path of the application (important for subdirectory installations)
function getBasePath() {
    $scriptName = dirname($_SERVER['SCRIPT_NAME']);
    return rtrim($scriptName, '/');
}

// Simple router
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = getBasePath();
debugLog("Request URI: $request_uri, Base path: $base_path");

// Remove base path and query string from the request URI
$path = parse_url($request_uri, PHP_URL_PATH);
if (!empty($base_path) && strpos($path, $base_path) === 0) {
    $path = substr($path, strlen($base_path));
}
$path = '/' . ltrim($path, '/');
debugLog("Normalized path: $path");

// Handle requests
if ($path === '/' || $path === '/index.php') {
    // Get stories for the main page
    $story_type = $_GET['type'] ?? 'top';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = STORIES_PER_PAGE; // Use the constant
    
    // Get stories for current page
    $stories = get_page_stories($story_type, $page, $per_page);
    
    // Include the template with stories data
    include 'templates/index.php';
} elseif (preg_match('/^\/summary\/(\d+)$/', $path, $matches)) {
    // Handle combined summary page
    $story_id = $matches[1];
    
    // Get story details from database
    $db = new SQLite3('hn_stories.db');
    $story = $db->querySingle("SELECT * FROM stories WHERE id = " . intval($story_id), true);
    
    if (!$story) {
        http_response_code(404);
        echo "Story not found";
        exit;
    }
    
    // Check if summary files exist
    $story_summary_file = "static/content/{$story_id}-story-summary.md";
    $comments_summary_file = "static/content/{$story_id}-comments-summary.md";
    
    $has_story_summary = file_exists($story_summary_file);
    $has_comments_summary = file_exists($comments_summary_file);
    
    if (!$has_story_summary && !$has_comments_summary) {
        http_response_code(404);
        echo "No summaries found for this story";
        exit;
    }
    
    // Load summaries if they exist
    $story_summary = '';
    $comments_summary = '';
    
    if ($has_story_summary) {
        $story_summary = file_get_contents($story_summary_file);
    }
    
    if ($has_comments_summary) {
        $comments_summary = file_get_contents($comments_summary_file);
    }
    
    // Include the summary template
    include 'templates/summary.php';
} elseif (preg_match('/^\/story-summary\/(\d+)$/', $path, $matches)) {
    // Handle story summary requests
    $story_id = $matches[1];
    $summary_file = "static/content/{$story_id}-story-summary.md";
    
    if (file_exists($summary_file)) {
        // Set content type to markdown
        header('Content-Type: text/markdown');
        readfile($summary_file);
    } else {
        http_response_code(404);
        echo "Story summary not found";
    }
} elseif (preg_match('/^\/comments-summary\/(\d+)$/', $path, $matches)) {
    // Handle comments summary requests
    $story_id = $matches[1];
    $summary_file = "static/content/{$story_id}-comments-summary.md";
    
    if (file_exists($summary_file)) {
        // Set content type to markdown
        header('Content-Type: text/markdown');
        readfile($summary_file);
    } else {
        http_response_code(404);
        echo "Comments summary not found";
    }
} elseif ($path === '/api/stories') {
    // API endpoint for stories
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    $stories = get_stories();
    echo json_encode($stories);
    debugLog("API stories returned " . count($stories) . " items");
} elseif ($path === '/api/stats') {
    // API endpoint for stats
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    echo json_encode(get_stats());
} elseif (strpos($path, '/static/') === 0) {
    // Serve static files
    $file = ltrim($path, '/');
    debugLog("Static file request: $file");
    if (file_exists($file)) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        
        // Set appropriate content type
        switch ($extension) {
            case 'css':
                header('Content-Type: text/css');
                break;
            case 'js':
                header('Content-Type: application/javascript');
                break;
            case 'jpg':
            case 'jpeg':
                header('Content-Type: image/jpeg');
                break;
            case 'png':
                header('Content-Type: image/png');
                break;
        }
        
        readfile($file);
    } else {
        debugLog("File not found: $file");
        http_response_code(404);
        echo '404 Not Found';
    }
} else {
    // Handle 404
    debugLog("404 Not Found: $path");
    http_response_code(404);
    echo '404 Not Found';
}