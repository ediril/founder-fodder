<?php

// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/defines.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../_env', '.founderfodder.env');
$dotenv->load();

// Prompts for AI summarization
const STORY_PROMPT = "Create summary of the following page for main topics and insights. Try to limit summary to 200 words. Don't restate the question, return just summary:\n------\n{content}";
const COMMENTS_PROMPT = "Create summary of the following discussion for main arguments, opinions, criticisms. Try to limit summary to 600 words. Don't restate the question, return just summary:\n------\n{content}";

// File naming constants
const STORY_SUMMARY_FILE = "story-summary.md";
const COMMENTS_SUMMARY_FILE = "comments-summary.md";

// Directory constants
const OUTPUT_DIR = "static/content";
const ORIGINALS_DIR = "_originals";
// Create these directories if they don't exist
if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0755, true);
}
if (!is_dir(ORIGINALS_DIR)) {
    mkdir(ORIGINALS_DIR, 0755, true);
}

// Hacker News API endpoints
const HN_BASE_URL = "https://hacker-news.firebaseio.com/v0";
const HN_ITEM_URL = HN_BASE_URL . "/item";

class StorySummarizer {
    private $dbPath;
    private $outputDir;
    private $originalsDir;
    private $client;
    private $model;
    private $lockFile;
    private $lockHandle;
    private $dryRun;
    
    public function __construct($dbPath = DB_PATH, $outputDir = OUTPUT_DIR, $originalsDir = ORIGINALS_DIR, $dryRun = false) {
        // Initialize file locking first
        $this->initializeLock();
        
        // Set up signal handlers for cleanup
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
        
        $this->dbPath = $dbPath;
        $this->outputDir = $outputDir;
        $this->originalsDir = $originalsDir;
        $this->dryRun = $dryRun;
        
        if ($dryRun) {
            echo "DRY-RUN MODE: Will not send content to Gemini API\n";
            $this->client = null;
            $this->model = null;
        } else {
            $apiKey = $_SERVER['GEMINI_API_KEY'] ?? null;
            if (!$apiKey) {
                throw new Exception('GEMINI_API_KEY environment variable not found');
            }
            
            // Initialize Gemini AI
            $this->client = Gemini::client($apiKey);
            $this->model = $this->client->generativeModel(model: 'gemini-2.0-flash-lite');
        }
        
        // Create directories if they don't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
        if (!is_dir($this->originalsDir)) {
            mkdir($this->originalsDir, 0755, true);
        }
    }
    
    /**
     * Initialize file locking to prevent multiple instances
     */
    private function initializeLock() {
        $this->lockFile = sys_get_temp_dir() . '/story_summarizer.lock';
        
        // Try to open the lock file
        $this->lockHandle = fopen($this->lockFile, 'c');
        if (!$this->lockHandle) {
            throw new Exception("Cannot create lock file: {$this->lockFile}");
        }
        
        // Try to acquire an exclusive lock
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            throw new Exception("Another instance of the story summarizer is already running. Lock file: {$this->lockFile}");
        }
        
        // Write current process ID to the lock file
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, getmypid() . "\n" . date('Y-m-d H:i:s') . "\n");
        fflush($this->lockHandle);
        
        // Register shutdown function to clean up lock
        register_shutdown_function([$this, 'releaseLock']);
        
        echo "Acquired process lock (PID: " . getmypid() . ")\n";
    }
    
    /**
     * Release the file lock
     */
    public function releaseLock() {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
            
            // Remove the lock file
            if (file_exists($this->lockFile)) {
                unlink($this->lockFile);
            }
            
            echo "Released process lock\n";
        }
    }
    
    /**
     * Handle signals for graceful shutdown
     */
    public function handleSignal(int $signal): void
    {
        echo "\nReceived signal {$signal}. Cleaning up and exiting...\n";
        $this->releaseLock();
        exit(0);
    }
    
    /**
     * Destructor to ensure lock is released
     */
    public function __destruct() {
        $this->releaseLock();
    }
    
    /**
     * Decode HTML entities in text to their corresponding characters
     */
    private function decodeHtmlEntities($text) {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Centralized HTTP request method with signal handling
     */
    private function fetchUrl(string $url, int $timeout = 10, array $headers = []): string|false
    {
        $defaultHeaders = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ];
        
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $allHeaders),
                'timeout' => $timeout
            ]
        ]);

        // Dispatch signals before making the request
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return @file_get_contents($url, false, $context);
    }
    
    /**
     * Fetch and extract main content from a story URL
     */
    private function fetchStoryContent($url) {
        if (!$url || strpos($url, "item?id=") !== false) {
            return "No URL provided or URL is a HN discussion.";
        }
        
        try {
            $content = $this->fetchUrl($url);
            if ($content === false) {
                return "Failed to fetch content from URL.";
            }
            
            // Parse the content with DOMDocument
            $dom = new DOMDocument();
            // Suppress warnings for malformed HTML
            libxml_use_internal_errors(true);
            $dom->loadHTML($content);
            libxml_clear_errors();
            
            // Remove script and style elements
            $scripts = $dom->getElementsByTagName('script');
            for ($i = $scripts->length - 1; $i >= 0; $i--) {
                $scripts->item($i)->parentNode->removeChild($scripts->item($i));
            }
            $styles = $dom->getElementsByTagName('style');
            for ($i = $styles->length - 1; $i >= 0; $i--) {
                $styles->item($i)->parentNode->removeChild($styles->item($i));
            }
            
            // Try to find the main content
            $mainContent = "";
            $article = $dom->getElementsByTagName('article')->item(0) ?? 
                      $dom->getElementsByTagName('main')->item(0) ?? 
                      $dom->getElementById('content') ?? 
                      $dom->getElementsByTagName('body')->item(0);
            
            if ($article) {
                $mainContent = trim($article->textContent);
            }
            
            // Clean up the content (remove excessive newlines, etc.)
            $lines = explode("\n", $mainContent);
            $cleanLines = array_filter(array_map('trim', $lines));
            $mainContent = implode("\n", $cleanLines);
            
            // Decode HTML entities
            $mainContent = $this->decodeHtmlEntities($mainContent);
            
            if (!$mainContent) {
                echo " .. could not find any story content\n";
            }
            
            return $mainContent;
        } catch (Exception $e) {
            return "Error fetching content: " . $e->getMessage();
        }
    }
    
    /**
     * Fetch a single comment by ID from the Hacker News API
     */
    private function fetchComment($commentId) {
        try {
            $url = HN_ITEM_URL . "/{$commentId}.json";
            $content = $this->fetchUrl($url);
            if ($content === false) {
                return null;
            }
            
            $comment = json_decode($content, true);
            if (!$comment || isset($comment['dead']) || isset($comment['deleted'])) {
                return null;
            }
            
            // Decode HTML entities in the comment text
            if (isset($comment['text'])) {
                $comment['text'] = $this->decodeHtmlEntities($comment['text']);
            }
            
            return $comment;
        } catch (Exception $e) {
            echo "Error fetching comment {$commentId}: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    /**
     * Recursively fetch comments and their replies with intelligent limiting
     */
    private function fetchCommentsRecursive($commentIds, $depth = 0, $maxDepth = 2) {
        if (!$commentIds || $depth > $maxDepth) {
            return [];
        }
        
        // Limit replies
        if ($depth > 0) {
            $maxReplies = 10; // Limit replies to 10 per comment
            $commentIds = array_slice($commentIds, 0, $maxReplies);
        }
        
        $comments = [];
        foreach ($commentIds as $commentId) {
            $comment = $this->fetchComment($commentId);
            if (!$comment) {
                continue;
            }
            
            // Extract the comment text
            $commentText = $comment['text'] ?? '';
            if (!$commentText) {
                continue;
            }
            
            // Create a comment object with text and replies
            $commentObj = [
                'id' => $commentId,
                'text' => $commentText,
                'by' => $comment['by'] ?? '',
                'time' => $comment['time'] ?? 0,
                'replies' => []
            ];
            
            // Recursively fetch replies if any (but limit depth and quantity)
            if (isset($comment['kids']) && $comment['kids'] && $depth < $maxDepth) {
                $replies = $this->fetchCommentsRecursive($comment['kids'], $depth + 1, $maxDepth);
                $commentObj['replies'] = $replies;
            }
            
            $comments[] = $commentObj;
            
            // Reduce delay significantly
            usleep(5000); // 0.005 second (5ms)
        }
        
        return $comments;
    }
    
    /**
     * Fetch and extract comments from a HN discussion URL using the API
     */
    private function fetchComments($commentsUrl) {
        // Extract the story ID from the comments URL
        // Format is typically: https://news.ycombinator.com/item?id=123456
        $parsedUrl = parse_url($commentsUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        $storyId = $queryParams['id'] ?? null;
        
        if (!$storyId) {
            return "Could not extract story ID from comments URL.";
        }
        
        // Fetch the story details to get the top-level comment IDs
        $url = HN_ITEM_URL . "/{$storyId}.json";
        $content = $this->fetchUrl($url);
        if ($content === false) {
            return "Failed to fetch story details.";
        }
        
        $story = json_decode($content, true);
        if (!$story || !isset($story['kids'])) {
            return "";
        }
        
        // Fetch all comments recursively
        echo "  Fetching comments for story {$storyId}...\n";
        $comments = $this->fetchCommentsRecursive($story['kids']);
        echo "  Fetched " . count($comments) . " top-level comments\n";
        
        // Format comments for summarization
        $combinedComments = "";
        foreach ($comments as $i => $comment) {
            $combinedComments .= $this->formatComment($comment, $i + 1);
        }
        
        if (!$combinedComments) {
            return "";
        }
        
        // Ensure all HTML entities are decoded
        $combinedComments = $this->decodeHtmlEntities($combinedComments);
        
        return $combinedComments;
    }
    
    /**
     * Format a comment and its replies for summarization
     */
    private function formatComment($comment, $index, $depth = 0) {
        $indent = str_repeat("  ", $depth);
        $result = "{$indent}Comment {$index}:\n{$indent}{$comment['text']}\n\n";
        
        // Add replies
        foreach ($comment['replies'] as $i => $reply) {
            $result .= $this->formatComment($reply, "{$index}." . ($i + 1), $depth + 1);
        }
        
        return $result;
    }
    
    /**
     * Remove unwanted lines from AI-generated content
     */
    private function cleanupContent($body) {
        if (!$body) {
            return $body;
        }
        
        // Split the text into lines
        $lines = explode("\n", $body);
        $cleanedLines = [];
        
        foreach ($lines as $line) {
            $lowerLine = strtolower(trim($line));
            
            // These tend to be at the beginning
            if (strpos($lowerLine, 'summary ') === 0 || 
                strpos($lowerLine, "here's ") === 0 || 
                strpos($lowerLine, 'here is ') === 0 ||
                strpos($lowerLine, 'okay, ') === 0) {
                continue;
            }
            
            // These seem to arrive at the end
            if (strpos($lowerLine, "you like me to") !== false || 
                strpos($lowerLine, "you want me to") !== false ||
                $lowerLine === "---" ||
                strpos($lowerLine, "let me know if ") === 0) {
                break;
            }
            
            $cleanedLines[] = $line;
        }
        
        // Join the lines back together
        return implode("\n", $cleanedLines);
    }
    
    private function summarize($content, $promptType) {
        try {
            // Define prompts based on content type
            if ($promptType === "story") {
                $prompt = str_replace('{content}', $content, STORY_PROMPT);
            } else { // comments
                $prompt = str_replace('{content}', $content, COMMENTS_PROMPT);
            }
            
            if ($this->dryRun) {
                $contentLength = strlen($content);
                $promptLength = strlen($prompt);
                echo "    [DRY-RUN] Would summarize {$promptType} content ({$contentLength} chars, prompt: {$promptLength} chars)\n";
                return "DRY-RUN MODE: This would be a {$promptType} summary generated from {$contentLength} characters of content.";
            }
            
            $result = $this->model->generateContent($prompt);
            return $result->text();
            
        } catch (Exception $e) {
            echo "ERROR during summarization: " . $e->getMessage() . "\n";
            return null;
        }
    }

    public function processStory($story, $downloadOnly = false, $storyType = null) {
        echo "Processing story {$story['id']}\n";

        $storySummaryFile = $this->outputDir . "/{$story['id']}-" . STORY_SUMMARY_FILE;
        if (file_exists($storySummaryFile)) {
            return;
        }

        $storyContent = "";
        $storyFile = $this->originalsDir . "/{$story['id']}-story.txt";
        if (!file_exists($storyFile) && $story['url']) {
            echo "Fetching story...\n";
            $storyContent = $this->fetchStoryContent($story['url']);
            
            if (strlen($storyContent) > 750) {
                file_put_contents($storyFile, $storyContent);
                echo "Saved story to {$storyFile}\n";
            } else {
                echo "Story is too short to summarize (length: " . strlen($storyContent) . ")\n";
                file_put_contents($storySummaryFile, "");
                return;
            }
        }
        
        if ($downloadOnly) {
            echo "  Download-only mode: skipping summarization\n";
            return;
        }

        if (!$storyContent && file_exists($storyFile)) {
            $storyContent = file_get_contents($storyFile);
            echo "Loaded existing story content...\n";
        }

        if ($storyContent) {
            echo "  Summarizing story (length: " . strlen($storyContent) . ")...\n";
            $startTime = microtime(true);
            
            $storySummary = $this->summarize($storyContent, "story");
            if ($storySummary) {
                $storySummary = $this->cleanupContent($storySummary);
                
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Would save story summary to {$storySummaryFile}\n";
                } else {
                    // Save the story summary
                    file_put_contents($storySummaryFile, $storySummary);
                    echo "  Saved story summary to {$storySummaryFile}\n";
                }
                
                // Calculate and display elapsed time
                $elapsedTime = microtime(true) - $startTime;
                echo "  Story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";
            }
        }
    }

    public function processComments($story, $downloadOnly = false, $storyType = null) {
        echo "Processing comments for story {$story['id']}\n";

        if ($storyType == 'new') {
            echo "  Skipping comments processing for new stories\n";
            return;
        }

        $commentsSummaryFile = $this->outputDir . "/{$story['id']}-" . COMMENTS_SUMMARY_FILE;
        if (file_exists($commentsSummaryFile)) {
            if ($story['fetched_at'] + 3600 * 72 < time()) {
                // if it's been more than 72 hours, keep the summary
                return;
            }
            $creationTime = filectime($commentsSummaryFile);
            if ($creationTime + 3600 * 6 > time()) {
                // if it's been less than 6 hours since creation, keep the summary
                return;
            }
            unlink($commentsSummaryFile);
        }

        $commentsContent = "";
        $commentsFile = $this->originalsDir . "/{$story['id']}-comments.txt";
        if (!file_exists($commentsFile) && $story['comments_url']) {
            $commentsCount = (int)($story['comments_count'] ?? 0);
            if ($commentsCount > 10) {
                echo "Fetching comments ({$commentsCount})...\n";
                $commentsContent = $this->fetchComments($story['comments_url']);
                if ($commentsContent) {
                    file_put_contents($commentsFile, $commentsContent);
                    echo "Comments saved...\n";
                } else {
                    echo "Failed to fetch comments\n";
                    return;
                }
            } else {
                echo "Not enough comments to summarize ({$commentsCount})\n";
                return;
            }
        }

        if ($downloadOnly) {
            echo "  Download-only mode: skipping summarization\n";
            return;
        }
        
        if (!$commentsContent && file_exists($commentsFile)) {
            $commentsContent = file_get_contents($commentsFile);
            echo "Loaded existing comments ...\n";
        }

        if ($commentsContent) {
            echo "  Summarizing comments (count: {$commentsCount})...\n";
            $startTime = microtime(true);
            
            $commentsSummary = $this->summarize($commentsContent, "comments");
            if ($commentsSummary) {
                $commentsSummary = $this->cleanupContent($commentsSummary);
                
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Would save comments {$commentsCount} summary to {$commentsSummaryFile}\n";
                } else {
                    // Save the comments summary
                    file_put_contents($commentsSummaryFile, $commentsSummary);
                    echo "  Saved {$commentsCount} comments summary\n";
                }
                
                // Calculate and display elapsed time
                $elapsedTime = microtime(true) - $startTime;
                echo "  Comments for story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";
            }
        }
    }
    
    /**
     * Run the summarization process
     */
    public function run($storyType = "top", $limit = 10, $downloadOnly = false) {
        echo "Looking for {$storyType} stories to process (limit: {$limit})...\n";
        
        try {
            $pdo = new PDO("sqlite:{$this->dbPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare('
                SELECT id, title, url, domain, comments_url, comments_count, fetched_at
                FROM stories
                WHERE type = ?
                ORDER BY ranking
            ');
            $stmt->execute([$storyType]);
            
            $storyCnt = 0;
            while ($story = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->processStory($story, $downloadOnly, $storyType);
                $this->processComments($story, $downloadOnly, $storyType);
                
                $storyCnt++;
                if ($limit > 0 && $storyCnt == $limit) {
                    break;
                }

                // Sleep to be nice to APIs and servers
                sleep(2);
            }
            
            echo "Completed processing of {$storyCnt} stories.\n";
            
        } catch (PDOException $e) {
            echo "Database error: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Process a single story by ID
     */
    public function processStoryById($storyId, $downloadOnly = false) {
        try {
            $pdo = new PDO("sqlite:{$this->dbPath}");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare('
                SELECT id, title, url, domain, comments_url, comments_count, type
                FROM stories
                WHERE id = ?
            ');
            $stmt->execute([$storyId]);
            
            $story = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($story) {
                $this->processStory($story, $downloadOnly, $story['type']);
                $this->processComments($story, $downloadOnly, $story['type']);
            } else {
                echo "Story ID {$storyId} not found in database.\n";
            }
            
        } catch (PDOException $e) {
            echo "Database error: " . $e->getMessage() . "\n";
        }
    }
}

// Command line interface
if ($argc > 1) {
    $options = getopt('', [
        'type:',
        'limit:',
        'db:',
        'output:',
        'download-only',
        'dry-run',
        'story-id:',
        'help'
    ]);
    
    if (isset($options['help'])) {
        echo "Usage: php summarize.php [options]\n";
        echo "Options:\n";
        echo "  --type=TYPE        Type of stories to summarize (top, new, best, all) [default: all]\n";
        echo "  --limit=N          Maximum number of stories to process per type [default: 100]\n";
        echo "  --db=PATH          Path to SQLite database [default: " . DB_PATH . "]\n";
        echo "  --output=PATH      Output directory for content files [default: " . OUTPUT_DIR . "]\n";
        echo "  --download-only    Download story and comments content without summarizing\n";
        echo "  --dry-run          Process and show what would be summarized without calling Gemini API\n";
        echo "  --story-id=ID      Process a single story by ID\n";
        echo "  --help             Show this help message\n";
        exit(0);
    }
    
    $storyType = $options['type'] ?? 'all';
    $limit = isset($options['limit']) ? (int)$options['limit'] : 100;
    $dbPath = $options['db'] ?? DB_PATH;
    $outputDir = $options['output'] ?? OUTPUT_DIR;
    $downloadOnly = isset($options['download-only']);
    $dryRun = isset($options['dry-run']);
    $storyId = isset($options['story-id']) ? (int)$options['story-id'] : null;
    
    try {
        $summarizer = new StorySummarizer($dbPath, $outputDir, ORIGINALS_DIR, $dryRun);
        
        if ($storyId) {
            $summarizer->processStoryById($storyId, $downloadOnly);
        } else {
            if ($storyType === 'all') {
                // Process all story types with the limit applying to each type
                $types = ['top', 'new', 'best'];
                foreach ($types as $type) {
                    echo "\n=== Processing {$type} stories ===\n";
                    $summarizer->run($type, $limit, $downloadOnly);
                }
                echo "\n=== Completed processing all story types ===\n";
            } else {
                // Process single story type
                $summarizer->run($storyType, $limit, $downloadOnly);
            }
        }

        // TODO: Delete stories, comments and summaries if they were fetched over 6 months ago
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "Story Summarizer\n";
    echo "Usage: php summarize.php --help for options\n";
    echo "Example: php summarize.php --type=top --limit=5\n";
    echo "Example: php summarize.php --limit=10 (processes all types with 10 stories each)\n";
    echo "Example: php summarize.php --dry-run --limit=5 (test mode without API calls)\n";
}

