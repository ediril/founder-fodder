<?php
declare(strict_types=1);

require_once __DIR__ . '/banalytiq/banalytiq.php';
record_visit();

require_once __DIR__ . '/common.php';

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
 * Get stories for a specific page with pagination
 */
function get_page_stories(string $story_type, int $page, int $per_page): array {
    try {
        // Calculate offset for pagination
        $offset = ($page - 1) * $per_page;
        
        debugLog("Getting page stories: type=$story_type, page=$page, per_page=$per_page, offset=$offset");
        
        // Get type ID for the story type
        $type_id = STORY_TYPES[$story_type] ?? null;
        if (!$type_id) {
            debugLog("Invalid story type: $story_type");
            throw new Exception("Invalid story type: $story_type");
        }
        
        $conn = get_db_connection();
        
        // Get total count for pagination
        $stmt = $conn->prepare('SELECT COUNT(*) as total FROM ranks WHERE type = ?');
        $stmt->execute([$type_id]);
        $result = $stmt->fetch();
        $total = (int)($result['total'] ?? 0);
        
        // Get stories by type, ordered by ranking
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp, r.type, r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
            ORDER BY r.rank
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$type_id, $per_page, $offset]);
        
        // Convert to array of associative arrays
        $stories = [];
        $story_ids = [];
        while ($row = $stmt->fetch()) {
            // Add additional fields
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            
            // Convert type ID back to string for compatibility
            $row['type'] = $story_type;
            
            $stories[] = $row;
            $story_ids[] = $row['id'];
        }
        
        // Batch check image and summary files for all stories at once
        if (!empty($story_ids)) {
            $image_file_types = batch_check_image_files($story_ids);
            $summary_files = batch_check_summary_files($story_ids);
            
            // Add file information to each story
            foreach ($stories as &$story) {
                // Generate image URL based on story ID if the image exists
                if ($image_file_types[$story['id']] ?? false) {
                    $story['image_url'] = "/static/img/stories/{$story['id']}.{$image_file_types[$story['id']]}";
                } else {
                    $story['image_url'] = "";
                }
                
                $story['summary_files'] = $summary_files[$story['id']] ?? [
                    'has_story_summary' => false,
                    'has_comments_summary' => false
                ];
            }
            unset($story); // Clean up reference
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
    } finally {
        close_db_connection($conn);
    }
}

/**
 * Get mixed stories: exactly 5 active, 3 top, 2 new, and 1 best stories per page
 * Stories are mixed together in a balanced way across the page
 */
function get_mixed_stories(int $page = 1): array {
    // Calculate total stories per page from individual story type counts
    $per_page = ACTIVE_STORIES_PER_PAGE + TOP_STORIES_PER_PAGE + NEW_STORIES_PER_PAGE + BEST_STORIES_PER_PAGE;
    
    try {
        debugLog("Getting mixed stories: page=$page, per_page=$per_page");
        
        $conn = get_db_connection();
        
        // Get total count of active stories for pagination
        $stmt = $conn->prepare('SELECT COUNT(*) as total FROM ranks WHERE type = ?');
        $stmt->execute([STORY_TYPES['active']]);
        $result = $stmt->fetch();
        $total_active = (int)($result['total'] ?? 0);
        
        // Calculate offsets for each story type
        $active_offset = ($page - 1) * ACTIVE_STORIES_PER_PAGE;
        $top_offset = 0; // Always show top-ranked stories
        $best_offset = 0; // Always show top-ranked stories
        $new_offset = 0; // Always show top-ranked stories
        
        // Get active stories
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp, r.type, r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
            ORDER BY r.rank
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([STORY_TYPES['active'], ACTIVE_STORIES_PER_PAGE, $active_offset]);
        
        $active_stories = [];
        while ($row = $stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'active';
            $active_stories[] = $row;
        }
        
        // Get top stories
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp, r.type, r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
            ORDER BY r.rank
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([STORY_TYPES['top'], TOP_STORIES_PER_PAGE, $top_offset]);
        
        $top_stories = [];
        while ($row = $stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'top';
            $top_stories[] = $row;
        }
        
        // Get new stories (only with >= 2 comments)
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp, r.type, r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ? AND s.comments_count >= 2
            ORDER BY r.rank
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([STORY_TYPES['new'], NEW_STORIES_PER_PAGE, $new_offset]);
        
        $new_stories = [];
        while ($row = $stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'new';
            $new_stories[] = $row;
        }
        
        // Get best stories
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, 
                   s.score, s.user, s.timestamp, r.type, r.rank as ranking
            FROM stories s
            INNER JOIN ranks r ON s.id = r.id
            WHERE r.type = ?
            ORDER BY r.rank
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([STORY_TYPES['best'], BEST_STORIES_PER_PAGE, $best_offset]);
        
        $best_stories = [];
        while ($row = $stmt->fetch()) {
            $row['time_ago'] = format_time_ago((int)$row['timestamp']);
            $row['formatted_time'] = date("Y-m-d H:i:s", (int)$row['timestamp']);
            $row['type'] = 'best';
            $best_stories[] = $row;
        }
        
        // Create mixed stream in sequential blocks: 5 active, 3 top, 2 new, 1 best
        $mixed_stories = [];
        
        // Add active stories
        for ($i = 0; $i < ACTIVE_STORIES_PER_PAGE && $i < count($active_stories); $i++) {
            $mixed_stories[] = $active_stories[$i];
        }
        
        // Add top stories
        for ($i = 0; $i < TOP_STORIES_PER_PAGE && $i < count($top_stories); $i++) {
            $mixed_stories[] = $top_stories[$i];
        }
        
        // Add new stories
        for ($i = 0; $i < NEW_STORIES_PER_PAGE && $i < count($new_stories); $i++) {
            $mixed_stories[] = $new_stories[$i];
        }
        
        // Add best stories
        for ($i = 0; $i < BEST_STORIES_PER_PAGE && $i < count($best_stories); $i++) {
            $mixed_stories[] = $best_stories[$i];
        }
        
        // Get all story IDs for batch processing
        $story_ids = array_column($mixed_stories, 'id');
        
        // Batch check image and summary files for all stories at once
        if (!empty($story_ids)) {
            $image_file_types = batch_check_image_files($story_ids);
            $summary_files = batch_check_summary_files($story_ids);
            
            // Add file information to each story
            foreach ($mixed_stories as &$story) {
                // Generate image URL based on story ID if the image exists
                if ($image_file_types[$story['id']] ?? false) {
                    $story['image_url'] = "/static/img/stories/{$story['id']}.{$image_file_types[$story['id']]}";
                } else {
                    $story['image_url'] = "";
                }
                
                $story['summary_files'] = $summary_files[$story['id']] ?? [
                    'has_story_summary' => false,
                    'has_comments_summary' => false
                ];
            }
            unset($story); // Clean up reference
        }
        
        // Calculate pagination based on active stories count (since they're the main stream)
        $pagination = [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total_active,
            'total_pages' => ceil($total_active / ACTIVE_STORIES_PER_PAGE),
            'has_more' => ($page * ACTIVE_STORIES_PER_PAGE) < $total_active,
            'next_page' => ($page * ACTIVE_STORIES_PER_PAGE) < $total_active ? $page + 1 : null,
        ];
        
        return [
            'stories' => $mixed_stories,
            'pagination' => $pagination,
            'type' => 'mixed'
        ];
    } catch (Exception $e) {
        debugLog("Error getting mixed stories: " . $e->getMessage());
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
            'type' => 'mixed',
            'error' => $e->getMessage()
        ];
    } finally {
        close_db_connection($conn);
    }
}

/**
 * Check if image files exist for multiple stories at once
 */
function batch_check_image_files(array $story_ids): array {
    $results = [];
    
    foreach ($story_ids as $story_id) {
        $img_path = "static/img/stories/{$story_id}.jpg";
        $results[$story_id] = file_exists($img_path) ? "jpg" : null;
        if (!$results[$story_id]) {
            $img_path = "static/img/stories/{$story_id}.png";
            $results[$story_id] = file_exists($img_path) ? "png" : null;
        }
        if (!$results[$story_id]) {
            $img_path = "static/img/stories/{$story_id}.webp";
            $results[$story_id] = file_exists($img_path) ? "webp" : null;
        }
    }
    
    return $results;
}

/**
 * Check if summary files exist for multiple stories at once
 */
function batch_check_summary_files(array $story_ids): array {
    $results = [];
    
    foreach ($story_ids as $story_id) {
        $story_summary_path = "static/content/{$story_id}-story-summary.md";
        $comments_summary_path = "static/content/{$story_id}-comments-summary.md";
        
        $results[$story_id] = [
            'has_story_summary' => file_exists($story_summary_path),
            'has_comments_summary' => file_exists($comments_summary_path)
        ];
    }
    
    return $results;
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
if ($path === null) {
    debugLog("Malformed URL: $request_uri");
    http_response_code(400);
    echo '400 Bad Request - Malformed URL';
    exit;
}

if (!empty($base_path) && strpos($path, $base_path) === 0) {
    $path = substr($path, strlen($base_path));
}
$path = '/' . ltrim($path, '/');
debugLog("Normalized path: $path");

/**
 * Generate asset URL with version for cache busting
 */
function asset_url(string $path): string {
    $full_path = ltrim($path, '/');
    if (file_exists($full_path)) {
        $version = filemtime($full_path);
        return $path . '?v=' . $version;
    }
    return $path;
}

// Handle requests
if ($path === '/' || $path === '/index.php') {
    // Get the active tab (news is default)
    $active_tab = $_GET['tab'] ?? 'news';
    
    // Check if a specific story type is requested
    $story_type = $_GET['type'] ?? null;
    
    // Initialize variables
    $stories = null;
    $last_updated = null;
    
    if ($story_type && in_array($story_type, ['top', 'new', 'active', 'best'])) {
        // Get stories for specific type
        $page = max(1, (int)($_GET['page'] ?? 1));
        // Calculate total stories per page (same as mixed view)
        $total_per_page = ACTIVE_STORIES_PER_PAGE + TOP_STORIES_PER_PAGE + NEW_STORIES_PER_PAGE + BEST_STORIES_PER_PAGE;
        $stories = get_page_stories($story_type, $page, $total_per_page);
        
        $db = new SQLite3(DB_PATH);
        // Get last updated time for this story type
        $result = $db->querySingle("
            SELECT MAX(s.fetched_at) as max_ts 
            FROM stories s 
            INNER JOIN ranks r ON s.id = r.id 
            WHERE r.type = " . intval(STORY_TYPES[$story_type]), true);
        $last_updated = $result['max_ts'];
    } elseif ($active_tab === 'news') {
        // Get mixed stories for the main page (active stories interspersed with top/best)
        $page = max(1, (int)($_GET['page'] ?? 1));
        
        // Get mixed stories for current page
        $stories = get_mixed_stories($page);
        
        $db = new SQLite3(DB_PATH);
        // Get last updated time from active stories (main stream)
        $result = $db->querySingle("
            SELECT MAX(s.fetched_at) as max_ts 
            FROM stories s 
            INNER JOIN ranks r ON s.id = r.id 
            WHERE r.type = " . intval(STORY_TYPES['active']), true);
        $last_updated = $result['max_ts'];
    }

    // Include the template with stories data and active tab
    include 'templates/index.php';

} elseif (preg_match('/^\/summary\/(\d+)$/', $path, $matches)) {
    // Handle combined summary page
    $story_id = $matches[1];
    
    // Get story details from database
    $db = new SQLite3(DB_PATH);
    $story = $db->querySingle("SELECT * FROM stories WHERE id = " . intval($story_id), true);

    // Bulletproof validation: ensure we have a valid story array with required fields
    if (!$story || !is_array($story) || !isset($story['id'], $story['title'], $story['timestamp'])) {
        http_response_code(404);
        echo "Story not found";
        exit;
    }
    
    $story['time_ago'] = format_time_ago((int)$story['timestamp']);
    
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
    $story_summary_last_updated = '';
    $comments_summary_last_updated = '';
    if ($has_story_summary) {
        $story_summary = file_get_contents($story_summary_file);
        $story_summary_last_updated = format_time_ago(filectime($story_summary_file));
    }
    if ($has_comments_summary) {
        $comments_summary = file_get_contents($comments_summary_file);
        $comments_summary_last_updated = format_time_ago(filectime($comments_summary_file));
    }
    
    include 'templates/summary.php';

} elseif ($path === '/about') {
    include 'templates/about.php';

} elseif ($path === '/legal') {
    include 'templates/legal.php';

} elseif ($path === '/privacy') {
    include 'templates/privacy.php';

} elseif ($path === '/blog') {
    include 'templates/blog.php';

} elseif ($path === '/favicon.ico') {
    // Handle favicon requests
    $file = 'favicon.ico';
    if (file_exists($file)) {
        header('Content-Type: image/x-icon');
        header('Cache-Control: public, max-age=31536000'); // 1 year
        readfile($file);
    } else {
        http_response_code(404);
        echo '404 Not Found';
    }
} elseif (strpos($path, '/static/') === 0) {
    // Serve static files
    $file = ltrim($path, '/');
    debugLog("Static file request: $file");
    if (file_exists($file)) {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        
        // Set cache control headers for static assets
        header('Cache-Control: public, max-age=31536000'); // 1 year
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
        
        // Set ETag based on file modification time
        $etag = '"' . md5_file($file) . '"';
        header('ETag: ' . $etag);
        
        // Check if client has a cached version
        $client_etag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($client_etag === $etag) {
            http_response_code(304);
            exit;
        }
        
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
            case 'webp':
                header('Content-Type: image/webp');
                break;
            case 'svg':
                header('Content-Type: image/svg+xml');
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