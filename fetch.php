<?php

// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/defines.php';

/**
 * Log message with timestamp
 */
function logMessage(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

class HackerNewsFetcher
{
    private const BASE_URL = "https://hacker-news.firebaseio.com/v0";
    private const ENDPOINTS = [
        "top" => self::BASE_URL . "/topstories.json",   // front-page
        "new" => self::BASE_URL . "/newstories.json",   // newest
        "best" => self::BASE_URL . "/beststories.json"  // highest-scoring
    ];
    private const ITEM_URL = self::BASE_URL . "/item";

    private string $dbPath;
    private string $imgDir;
    private string $lockFile;
    private $lockHandle;
    private ?\PDO $pdo = null;

    public function __construct(string $dbPath = DB_PATH, string $imgDir = "static/img/stories")
    {
        $this->dbPath = $dbPath;
        $this->imgDir = $imgDir;
        $this->lockFile = sys_get_temp_dir() . '/hackernews_fetcher.lock';
        
        // Check if another instance is running
        if (!$this->acquireLock()) {
            logMessage("Another instance of the fetcher is already running. Exiting.");
            exit(1);
        }
        
        // Set up signal handlers for cleanup
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
        
        // Set up shutdown function for cleanup
        register_shutdown_function([$this, 'cleanup']);
        
        $this->initDb();
        
        // Ensure image directory exists
        if (!is_dir($this->imgDir)) {
            mkdir($this->imgDir, 0755, true);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function acquireLock(): bool
    {
        $this->lockHandle = fopen($this->lockFile, 'w');
        if (!$this->lockHandle) {
            return false;
        }
        
        // Try to get an exclusive lock
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }
        
        // Write PID to lock file
        fwrite($this->lockHandle, getmypid());
        fflush($this->lockHandle);
        
        return true;
    }

    public function cleanup(): void
    {
        // Close database connection explicitly
        if (isset($this->pdo)) {
            $this->pdo = null;
        }
        
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    public function handleSignal(int $signal): void
    {
        logMessage("Received signal {$signal}. Cleaning up and exiting...");
        $this->cleanup();
        exit(0);
    }

    private function initDb(): void
    {
        $this->pdo = new \PDO("sqlite:{$this->dbPath}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        // Configure SQLite for better reliability and performance
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA temp_store = MEMORY');
        $this->pdo->exec('PRAGMA mmap_size = 268435456'); // 256MB
        
        // Create the stories table if it doesn't exist
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS stories (
                id INTEGER PRIMARY KEY,
                title TEXT,
                url TEXT,
                domain TEXT,
                comments_url TEXT,
                comments_count INTEGER,
                score INTEGER,
                user TEXT,
                timestamp INTEGER,
                fetched_at INTEGER,
                type TEXT,
                ranking INTEGER
            )
        ');
        
        // Create indices for faster queries
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_story_type ON stories(type, ranking)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_story_timestamp ON stories(timestamp)');
    }

    private function fetchUrl(string $url, int $timeout = 30, bool $headersOnly = false): string|array|false
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'method' => $headersOnly ? 'HEAD' : 'GET'
            ]
        ]);

        // Dispatch signals before making the request
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        if ($headersOnly) {
            return @get_headers($url, true, $context);
        } else {
            return @file_get_contents($url, false, $context);
        }
    }

    private function fetchStoryIds(string $storyType): array
    {
        $url = self::ENDPOINTS[$storyType] ?? null;
        if (!$url) {
            echo "Invalid story type: {$storyType}\n";
            return [];
        }

        $response = $this->fetchUrl($url);
        if ($response === false) {
            echo "Error fetching {$storyType} stories\n";
            return [];
        }

        $data = json_decode($response, true);
        return $data ?? [];
    }

    private function fetchStoryDetails(int $storyId): ?array
    {
        $url = self::ITEM_URL . "/{$storyId}.json";
        
        $response = $this->fetchUrl($url);
        if ($response === false) {
            echo "Error fetching story {$storyId}\n";
            return null;
        }

        return json_decode($response, true);
    }

    private function saveStoryImage(int $storyId, ?string $imageUrl): void
    {
        // Check if image already exists with any extension
        $extensions = ['.jpg', '.png', '.gif', '.webp'];
        foreach ($extensions as $ext) {
            $filepath = $this->imgDir . "/{$storyId}{$ext}";
            if (file_exists($filepath)) {
                return; // Silently skip if image already exists
            }
        }

        // If no URL or invalid URL, don't save anything
        if (!$imageUrl || !str_starts_with($imageUrl, 'http')) {
            echo "No valid image URL for story {$storyId}\n";
            return;
        }

        try {
            // First check if the URL is actually an image
            $headers = $this->fetchUrl($imageUrl, 10, true);
            $contentType = '';
            if ($headers && isset($headers['Content-Type'])) {
                $contentType = is_array($headers['Content-Type']) 
                    ? end($headers['Content-Type']) 
                    : $headers['Content-Type'];
            }

            // If it's not an image, try to extract an image from the page
            if (!str_starts_with($contentType, 'image/')) {
                $imageUrl = $this->extractImageFromPage($imageUrl);
                if (!$imageUrl) {
                    return;
                }
            }

            // Download the image
            $imageData = $this->fetchUrl($imageUrl, 10);
            if ($imageData === false) {
                echo "Failed to download image for story {$storyId}\n";
                return;
            }

            // Determine file extension
            $ext = '.jpg'; // default
            if (str_contains($contentType, 'png')) {
                $ext = '.png';
            } elseif (str_contains($contentType, 'gif')) {
                $ext = '.gif';
            } elseif (str_contains($contentType, 'webp')) {
                $ext = '.webp';
            }

            $filepath = $this->imgDir . "/{$storyId}{$ext}";
            if (file_put_contents($filepath, $imageData) !== false) {
                echo "Successfully downloaded image for story {$storyId}\n";
            }

        } catch (\Exception $e) {
            echo "Error downloading image for story {$storyId}: " . $e->getMessage() . "\n";
        }
    }

    private function extractImageFromPage(string $url): ?string
    {
        try {
            $html = $this->fetchUrl($url, 10);
            if ($html === false) {
                return null;
            }

            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            $parsedUrl = parse_url($url);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

            // Try Open Graph image
            $ogImage = $xpath->query('//meta[@property="og:image"]/@content');
            if ($ogImage->length > 0) {
                $imgUrl = $ogImage->item(0)->nodeValue;
                if (!str_starts_with($imgUrl, 'http')) {
                    $imgUrl = $baseUrl . '/' . ltrim($imgUrl, '/');
                }
                return $imgUrl;
            }

            // Try Twitter card image
            $twitterImage = $xpath->query('//meta[@name="twitter:image"]/@content');
            if ($twitterImage->length > 0) {
                $imgUrl = $twitterImage->item(0)->nodeValue;
                if (!str_starts_with($imgUrl, 'http')) {
                    $imgUrl = $baseUrl . '/' . ltrim($imgUrl, '/');
                }
                return $imgUrl;
            }

            // Look for large images in content
            $images = $xpath->query('//img[@src]');
            foreach ($images as $img) {
                if (!($img instanceof \DOMElement)) continue;
                
                $src = $img->getAttribute('src');
                if (empty($src)) continue;

                // Skip icons, logos, avatars, etc.
                $skipTerms = ['icon', 'logo', 'avatar', 'favicon', 'spinner', 'button'];
                if (array_any($skipTerms, fn($term) => str_contains(strtolower($src), $term))) {
                    continue;
                }

                // Make relative URLs absolute
                if (!str_starts_with($src, 'http')) {
                    $src = $baseUrl . '/' . ltrim($src, '/');
                }

                // Check dimensions if available
                $width = $img->getAttribute('width');
                $height = $img->getAttribute('height');

                if ($width && $height && (int)$width >= 300 && (int)$height >= 300) {
                    return $src;
                }
            }

            return null;
        } catch (\Exception $e) {
            echo "Error extracting image from {$url}: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function extractImageUrl(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        try {
            $parsedUrl = parse_url($url);
            $path = strtolower($parsedUrl['path'] ?? '');

            // Skip common non-HTML file types
            $skipExtensions = [
                '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
                '.txt', '.rtf', '.csv', '.zip', '.rar', '.tar', '.gz',
                '.mp3', '.mp4', '.avi', '.mov', '.wmv', '.flv', '.mkv',
                '.exe', '.dmg', '.iso', '.img', '.bin', '.dat'
            ];

            foreach ($skipExtensions as $ext) {
                if (str_ends_with($path, $ext)) {
                    echo "Skipping image extraction for non-HTML url: {$url}\n";
                    return null;
                }
            }

            print("Fetching page {$url}\n");

            $html = $this->fetchUrl($url, 10);
            if ($html === false) {
                echo "Failed to fetch page {$url}\n";
                return null;
            }

            return $this->extractImageFromPage($url);

        } catch (\Exception $e) {
            echo "Error extracting image from {$url}: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return "";
        }

        $parsedUrl = parse_url($url);
        return $parsedUrl['host'] ?? "";
    }

    private function storeStory(array $story, string $storyType, int $ranking): void
    {
        if (empty($story)) {
            return;
        }

        // Extract fields with defaults for missing values
        $storyId = $story['id'] ?? 0;
        $title = $story['title'] ?? '';
        $url = $story['url'] ?? '';
        $domain = $this->extractDomain($url);
        $commentsUrl = "https://news.ycombinator.com/item?id={$storyId}";
        $commentsCount = $story['descendants'] ?? 0;
        $score = $story['score'] ?? 0;
        $user = $story['by'] ?? '';
        $timestamp = $story['time'] ?? 0;
        $fetchedAt = time();

        // Check if story already exists in the database
        $stmt = $this->pdo->prepare('SELECT id FROM stories WHERE id = ?');
        $stmt->execute([$storyId]);
        $existing = $stmt->fetch();

        // Check if image already exists for this story
        $imageExists = false;
        $extensions = ['.jpg', '.png', '.gif', '.webp'];
        foreach ($extensions as $ext) {
            if (file_exists($this->imgDir . "/{$storyId}{$ext}")) {
                $imageExists = true;
                break;
            }
        }

        // Only try to fetch image if it doesn't exist
        if (!$imageExists && !empty($url)) {
            try {
                $imageUrl = $this->extractImageUrl($url);
                $this->saveStoryImage($storyId, $imageUrl);
            } catch (\Exception $e) {
                echo "Error processing image for story {$storyId}: " . $e->getMessage() . "\n";
                $this->saveStoryImage($storyId, null);
            }
        } elseif (!$imageExists) {
            $this->saveStoryImage($storyId, null);
        }

        if ($existing) {
            // Update existing story
            $stmt = $this->pdo->prepare('
                UPDATE stories
                SET title = ?, url = ?, domain = ?, comments_url = ?,
                    comments_count = ?, score = ?, user = ?,
                    timestamp = ?, fetched_at = ?, type = ?, ranking = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $title, $url, $domain, $commentsUrl, $commentsCount, $score,
                $user, $timestamp, $fetchedAt, $storyType, $ranking, $storyId
            ]);
        } else {
            // Insert new story
            $stmt = $this->pdo->prepare('
                INSERT INTO stories
                (id, title, url, domain, comments_url, comments_count, 
                 score, user, timestamp, fetched_at, type, ranking)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $storyId, $title, $url, $domain, $commentsUrl, $commentsCount,
                $score, $user, $timestamp, $fetchedAt, $storyType, $ranking
            ]);
        }
    }

    public function fetchAndStore(string $storyType = "top", int $limit = 100): int
    {
        logMessage("Fetching {$storyType} stories...");
        $storyIds = $this->fetchStoryIds($storyType);

        if (empty($storyIds)) {
            logMessage("No {$storyType} story IDs found");
            return 0;
        }

        // Limit the number of stories to process
        $storyIds = array_slice($storyIds, 0, $limit);
        $newStoriesCount = count($storyIds);

        try {
            $this->pdo->beginTransaction();
            
            // Shift down the rankings of existing stories that aren't being refetched
            $placeholders = str_repeat('?,', count($storyIds) - 1) . '?';
            $stmt = $this->pdo->prepare("
                UPDATE stories 
                SET ranking = ranking + ?
                WHERE type = ? AND id NOT IN ({$placeholders})
            ");
            $params = array_merge([$newStoriesCount, $storyType], $storyIds);
            $stmt->execute($params);

            $count = 0;
            foreach ($storyIds as $ranking => $storyId) {
                $story = $this->fetchStoryDetails($storyId);
                if ($story) {
                    $this->storeStory($story, $storyType, $ranking);
                    $count++;

                                    // Print progress every 10 stories
                if ($count % 10 === 0) {
                    logMessage("Processed {$count}/" . count($storyIds) . " {$storyType} stories");
                }

                    // Small delay to be nice to the API
                    usleep(100000); // 0.1 seconds
                }
            }
            
            $this->pdo->commit();
            logMessage("Completed fetching {$count} {$storyType} stories");
            
            return $count;
            
        } catch (\Exception $e) {
            $this->pdo->rollback();
            logMessage("Error during batch processing: " . $e->getMessage());
            throw $e;
        }
    }

    public function fetchAll(int $limit = 100): int
    {
        $total = 0;
        foreach (array_keys(self::ENDPOINTS) as $storyType) {
            $count = $this->fetchAndStore($storyType, $limit);
            $total += $count;
        }

        logMessage("Total stories fetched: {$total}");
                
        return $total;
    }

    /**
     * Fetch and store a single story by ID
     */
    public function fetchStoryById(int $storyId): bool
    {
        echo "Fetching story {$storyId}...\n";
        
        $story = $this->fetchStoryDetails($storyId);
        if (!$story) {
            echo "Story {$storyId} not found or could not be fetched\n";
            return false;
        }

        // For single story fetch, we don't have ranking context, so use 0
        // and don't shift existing rankings
        $this->storeStory($story, 'byid', 0);
        
        echo "Successfully fetched and stored story {$storyId}\n";
        return true;
    }

    /**
     * Remove images from static/img/stories that don't exist in the database.
     */
    function cleanupUnusedImages(): void
    {
        echo "\nCleaning up unused images...\n";
        
        try {
            // Get all story IDs from the database
            $stmt = $this->pdo->prepare("SELECT id FROM stories");
            $stmt->execute();
            $storyIds = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $storyIds[] = (string)$row['id'];
            }
            
            // Check if image directory exists
            if (!is_dir($this->imgDir)) {
                echo "Warning: Image directory {$this->imgDir} does not exist, skipping cleanup.\n";
                return;
            }
            
            // Get all image files in the static/img/stories directory
            $imageFiles = [];
            $allowedExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
            
            $files = scandir($this->imgDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                // Check if file has an allowed image extension
                $hasValidExtension = false;
                foreach ($allowedExtensions as $ext) {
                    if (str_ends_with(strtolower($file), $ext)) {
                        $hasValidExtension = true;
                        break;
                    }
                }
                
                if ($hasValidExtension) {
                    $imageFiles[] = $file;
                }
            }
            
            // Find images that don't have corresponding stories in the database
            $unusedImages = [];
            foreach ($imageFiles as $imageFile) {
                // Extract story ID from image filename (assuming format: story_id.ext)
                $storyId = pathinfo($imageFile, PATHINFO_FILENAME);
                if (!in_array($storyId, $storyIds)) {
                    $unusedImages[] = $imageFile;
                }
            }
            
            // Remove unused images
            $removedCount = 0;
            foreach ($unusedImages as $imageFile) {
                $imagePath = $this->imgDir . '/' . $imageFile;
                try {
                    if (unlink($imagePath)) {
                        echo "Removed unused image: {$imageFile}\n";
                        $removedCount++;
                    }
                } catch (\Exception $e) {
                    echo "Error removing image {$imageFile}: " . $e->getMessage() . "\n";
                }
            }
            
            echo "Cleanup complete. Removed {$removedCount} unused images.\n";
            
        } catch (\Exception $e) {
            echo "Error during image cleanup: " . $e->getMessage() . "\n";
        }
    }
}

// Helper function for array_any (not available in PHP 8.3)
if (!function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool
    {
        foreach ($array as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }
}

// Command line interface
if (php_sapi_name() === 'cli') {
    // Define valid options
    $validOptions = ['type:', 'limit:', 'db:', 'story-id:', 'help'];
    $options = getopt('', $validOptions);
    
    // Check for unknown arguments by comparing $argv with parsed options
    $knownArgs = [];
    foreach ($options as $key => $value) {
        if ($key === 'help') {
            $knownArgs[] = '--help';
        } else {
            $knownArgs[] = "--{$key}={$value}";
        }
    }
    
    // Find any arguments that weren't recognized
    $unknownArgs = [];
    for ($i = 1; $i < $argc; $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $found = false;
            foreach ($knownArgs as $knownArg) {
                if ($arg === $knownArg || str_starts_with($arg, explode('=', $knownArg)[0] . '=')) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $unknownArgs[] = $arg;
            }
        }
    }
    
    // Exit with error if unknown arguments found
    if (!empty($unknownArgs)) {
        echo "Error: Unknown argument(s): " . implode(', ', $unknownArgs) . "\n";
        echo "Use --help to see valid options.\n";
        exit(1);
    }
    
    if (isset($options['help'])) {
        echo "Usage: php fetch.php [options]\n";
        echo "Options:\n";
        echo "  --type=TYPE     Type of stories to fetch: top, new, best, all (default: all)\n";
        echo "  --limit=LIMIT   Maximum number of stories to fetch per type (default: 100)\n";
        echo "  --db=PATH       Path to SQLite database (default: stories.db)\n";
        echo "  --story-id=ID   Fetch a single story by ID\n";
        echo "  --help          Show this help message\n";
        exit(0);
    }
    
    $type = $options['type'] ?? 'all';
    $limit = (int)($options['limit'] ?? 100);
    $db = $options['db'] ?? DB_PATH;
    $storyId = isset($options['story-id']) ? (int)$options['story-id'] : null;
    
    $validTypes = ['top', 'new', 'best', 'all'];
    if (!in_array($type, $validTypes)) {
        echo "Error: Invalid type '{$type}'. Valid types are: " . implode(', ', $validTypes) . "\n";
        exit(1);
    }
    
    $fetcher = new HackerNewsFetcher($db, "static/img/stories");
    
    if ($storyId) {
        // Fetch single story by ID
        $success = $fetcher->fetchStoryById($storyId);
        if (!$success) {
            exit(1);
        }
    } else {
        // Fetch stories by type
        if ($type === 'all') {
            $fetcher->fetchAll($limit);
        } else {
            $fetcher->fetchAndStore($type, $limit);
        }

        // Clean up unused images after fetching
        $fetcher->cleanupUnusedImages();
    }
} 