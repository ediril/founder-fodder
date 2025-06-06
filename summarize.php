<?php

// Security: Only allow execution from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('404 Not Found');
}

require_once __DIR__ . '/common.php';
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
const OUTPUT_DIR = __DIR__ . "/static/content";
const ORIGINALS_DIR = __DIR__ . "/_originals";
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
    private $outputDir;
    private $originalsDir;
    private $client;
    private $model;
    private $lockHandle;
    private $dryRun;
    private ?\PDO $pdo = null;
    
    public function __construct($dbPath = DB_PATH, $outputDir = OUTPUT_DIR, $originalsDir = ORIGINALS_DIR, $dryRun = false) {
        $this->lockHandle = acquireLock(SUMMARIZER_LOCK_FILE_NAME);

        setup_signal_handlers();
        register_shutdown_function([$this, 'releaseResources']);

        check_if_other_process_running(FETCHER_LOCK_FILE_NAME);

        // write PID to lock file
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, getmypid() . "\n" . date('Y-m-d H:i:s') . "\n");
        fflush($this->lockHandle);

        // is the other process running?
        $this->outputDir = $outputDir;
        $this->originalsDir = $originalsDir;
        $this->dryRun = $dryRun;
        
        // Initialize database connection
        $this->pdo = get_db_connection($dbPath);
        
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
    
    public function releaseResources(): void
    {
        releaseLock($this->lockHandle, SUMMARIZER_LOCK_FILE_NAME);
        $this->lockHandle = null;
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

        dispatch_signal();

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
     * Fetch multiple comments concurrently using curl_multi
     */
    private function fetchCommentsBatch($commentIds, $batchSize = 50) {
        if (empty($commentIds)) {
            return [];
        }
        
        // Process comments in smaller batches for better reliability
        $batches = array_chunk($commentIds, $batchSize);
        $allComments = [];
        
        foreach ($batches as $batchIndex => $batch) {
            dispatch_signal();
            
            echo "    Processing batch " . ($batchIndex + 1) . "/" . count($batches) . " (" . count($batch) . " comments)...\n";
            
            // Use curl_multi for concurrent requests
            $multiHandle = curl_multi_init();
            $curlHandles = [];
            
            // Set up all curl handles
            foreach ($batch as $commentId) {
                $url = HN_ITEM_URL . "/{$commentId}.json";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; FounderFodder/1.0)');
                
                curl_multi_add_handle($multiHandle, $ch);
                $curlHandles[$commentId] = $ch;
            }
            
            // Execute all requests concurrently
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
                
                dispatch_signal();
            } while ($running > 0);
            
            // Collect results
            $batchComments = [];
            foreach ($curlHandles as $commentId => $ch) {
                $content = curl_multi_getcontent($ch);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
                
                if ($content !== false) {
                    $comment = json_decode($content, true);
                    if ($comment && !isset($comment['dead']) && !isset($comment['deleted']) && isset($comment['text'])) {
                        $comment['text'] = $this->decodeHtmlEntities($comment['text']);
                        $batchComments[$commentId] = $comment;
                    }
                }
            }
            
            curl_multi_close($multiHandle);
            $allComments = $allComments + $batchComments;
            echo "    Batch " . ($batchIndex + 1) . " completed: " . count($batchComments) . " valid comments\n";
        }
        
        return $allComments;
    }
    
    /**
     * Collect comment IDs breadth-first up to the limit
     */
    private function collectCommentIds($commentIds) {
        $allIds = [];
        $queue = $commentIds; // Start with top-level comment IDs
        
        echo "  Collecting comment IDs breadth-first (max: " . MAX_COMMENTS_TO_FETCH . ")...\n";
        
        // Keep collecting until queue is empty or we have enough IDs
        while (!empty($queue) && count($allIds) < MAX_COMMENTS_TO_FETCH) {
            $remaining = MAX_COMMENTS_TO_FETCH - count($allIds);
            $currentBatch = array_splice($queue, 0, min(50, $remaining)); // Process in batches of 50
            $allIds = array_merge($allIds, $currentBatch);
            
            // Fetch this batch to get reply IDs (only if we still need more)
            if (count($allIds) < MAX_COMMENTS_TO_FETCH) {
                $batchComments = $this->fetchCommentsBatch($currentBatch);
                
                // Add reply IDs to the queue for further processing
                foreach ($currentBatch as $commentId) {
                    if (isset($batchComments[$commentId]['kids'])) {
                        $replyIds = $batchComments[$commentId]['kids'];
                        $queue = array_merge($queue, $replyIds);
                    }
                }
            }
            
            echo "    Collected " . count($allIds) . " comment IDs so far...\n";
        }
        
        if (count($allIds) >= MAX_COMMENTS_TO_FETCH) {
            echo "  Reached collection limit of " . MAX_COMMENTS_TO_FETCH . " comment IDs\n";
        }
        
        echo "  Collected " . count($allIds) . " comment IDs total\n";
        
        return $allIds;
    }

    /**
     * Build comment tree from flat comment data
     */
    private function buildCommentTree($topLevelIds, $allComments) {
        $comments = [];
        
        foreach ($topLevelIds as $commentId) {
            if (isset($allComments[$commentId])) {
                $comment = $this->buildCommentWithReplies($commentId, $allComments);
                if ($comment) {
                    $comments[] = $comment;
                }
            }
        }
        
        return $comments;
    }
    
    /**
     * Recursively build a comment with its replies
     */
    private function buildCommentWithReplies($commentId, $allComments) {
        if (!isset($allComments[$commentId])) {
            return null;
        }
        
        $comment = $allComments[$commentId];
        $commentText = $comment['text'] ?? '';
        
        if (!$commentText) {
            return null;
        }
        
        $commentObj = [
            'id' => $commentId,
            'text' => $commentText,
            'by' => $comment['by'] ?? '',
            'time' => $comment['time'] ?? 0,
            'replies' => []
        ];
        
        // Add replies if they exist in our collected comments
        if (isset($comment['kids'])) {
            foreach ($comment['kids'] as $replyId) {
                $reply = $this->buildCommentWithReplies($replyId, $allComments);
                if ($reply) {
                    $commentObj['replies'][] = $reply;
                }
            }
        }
        
        return $commentObj;
    }

    /**
     * Fetch comments using efficient breadth-first approach
     */
    private function fetchCommentsEfficient($topLevelIds) {
        // Step 1: Collect all comment IDs breadth-first (no limit here)
        $allCommentIds = $this->collectCommentIds($topLevelIds);
        
        echo "  Collected " . count($allCommentIds) . " comment IDs total\n";
        echo "  Batch fetching all comments...\n";
        
        // Step 2: Fetch comments in batches and limit by valid comments
        $allComments = [];
        $batches = array_chunk($allCommentIds, 50);
        
        foreach ($batches as $batchIndex => $batch) {
            if (count($allComments) >= MAX_COMMENTS_TO_FETCH) {
                echo "  Reached limit of " . MAX_COMMENTS_TO_FETCH . " valid comments\n";
                break;
            }
            
            $batchComments = $this->fetchCommentsBatch($batch);
            
            // Only add comments up to the limit
            foreach ($batchComments as $commentId => $comment) {
                if (count($allComments) < MAX_COMMENTS_TO_FETCH) {
                    $allComments[$commentId] = $comment;
                } else {
                    break;
                }
            }
            
            echo "  Fetched " . count($allComments) . " valid comments so far...\n";
            
            if (count($allComments) >= MAX_COMMENTS_TO_FETCH) {
                echo "  Reached limit of " . MAX_COMMENTS_TO_FETCH . " valid comments\n";
                break;
            }
        }
        
        echo "  Successfully fetched " . count($allComments) . " valid comments\n";
        echo "  Building comment tree...\n";
        
        // Step 3: Build the tree structure
        $comments = $this->buildCommentTree($topLevelIds, $allComments);
        
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
        
        // Fetch all comments using efficient breadth-first approach
        echo "  Fetching comments for story {$storyId} ...\n";
        $comments = $this->fetchCommentsEfficient($story['kids']);
        
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
        $storySummaryFile = $this->outputDir . "/{$story['id']}-" . STORY_SUMMARY_FILE;
        if (file_exists($storySummaryFile)) {
            return;
        }

        logMessage("Processing content for story {$story['id']}");
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
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Story {$story['id']} processed in " . number_format($elapsedTime, 2) . " sec\n";
                } else {
                    echo "  Story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";
                }
            }
        }
    }

    public function processComments($story, $downloadOnly = false, $storyType = null) {
        if ($storyType == 'new') {
            echo "  Skipping comments processing for new stories\n";
            return;
        }

        logMessage("Processing comments for story {$story['id']}");
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
                    if ($story['comments_count'] > 10) {
            echo "Fetching comments (will fetch up to " . MAX_COMMENTS_TO_FETCH . " valid comments)...\n";
            $commentsContent = $this->fetchComments($story['comments_url']);
            if ($commentsContent) {
                file_put_contents($commentsFile, $commentsContent);
                echo "Comments saved...\n";
            } else {
                echo "Failed to fetch comments\n";
                return;
            }
            } else {
                echo "Not enough comments to summarize ({$story['comments_count']})\n";
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
            echo "  Summarizing comments (count: {$story['comments_count']})...\n";
            $startTime = microtime(true);
            
            $commentsSummary = $this->summarize($commentsContent, "comments");
            if ($commentsSummary) {
                $commentsSummary = $this->cleanupContent($commentsSummary);
                
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Would save comments {$story['comments_count']} summary to {$commentsSummaryFile}\n";
                } else {
                    // Save the comments summary
                    file_put_contents($commentsSummaryFile, $commentsSummary);
                    echo "  Saved {$story['comments_count']} comments summary\n";
                }
                
                // Calculate and display elapsed time
                $elapsedTime = microtime(true) - $startTime;
                if ($this->dryRun) {
                    echo "  [DRY-RUN] Comments for story {$story['id']} processed in " . number_format($elapsedTime, 2) . " sec\n";
                } else {
                    echo "  Comments for story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";
                }
            }
        }
    }
    
    /**
     * Run the summarization process
     */
    public function run($storyType = "top", $limit = 10, $downloadOnly = false) {
        logMessage("Looking for {$storyType} stories to process (limit: {$limit})...");
        
        // Get type ID for the story type
        $typeId = STORY_TYPES[$storyType] ?? null;
        if (!$typeId) {
            logMessage("Invalid story type: {$storyType}");
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare('
                SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, s.fetched_at
                FROM stories s
                INNER JOIN ranks r ON s.id = r.id
                WHERE r.type = ?
                ORDER BY r.rank
            ');
            $stmt->execute([$typeId]);
            
            $storyCnt = 0;
            while ($story = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->processStory($story, $downloadOnly, $storyType);
                $this->processComments($story, $downloadOnly, $storyType);
                
                $storyCnt++;
                if ($limit > 0 && $storyCnt == $limit) {
                    break;
                }

                // Sleep to be nice to APIs and servers
                sleep(1);
            }
            
            logMessage("Completed processing of {$storyCnt} stories.");
            
        } catch (PDOException $e) {
            logMessage("Database error: " . $e->getMessage());
        }
    }
    
    /**
     * Process a single story by ID
     */
    public function processStoryById($storyId, $downloadOnly = false) {
        try {
            $stmt = $this->pdo->prepare('
                SELECT s.id, s.title, s.url, s.domain, s.comments_url, s.comments_count, s.fetched_at, r.type
                FROM stories s
                INNER JOIN ranks r ON s.id = r.id
                WHERE s.id = ?
                LIMIT 1
            ');
            $stmt->execute([$storyId]);
            
            $story = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($story) {
                // Convert type ID back to string
                $typeMap = array_flip(STORY_TYPES);
                $storyTypeString = $typeMap[$story['type']] ?? throw new Exception("Unknown type for story-id {$storyId}");
                
                $this->processStory($story, $downloadOnly, $storyTypeString);
                $this->processComments($story, $downloadOnly, $storyTypeString);
            } else {
                logMessage("Story ID {$storyId} not found in database.");
            }
            
        } catch (PDOException $e) {
            logMessage("Database error: " . $e->getMessage());
        }
    }
}

$options = getopt('h', [
    'type:',
    'limit:',
    'db:',
    'output:',
    'download-only',
    'dry-run',
    'story-id:',
    'help'
]);

if (isset($options['help']) || isset($options['h'])) {
    echo "Usage: php summarize.php [options]\n";
    echo "Options:\n";
    echo "  --type=TYPE        Type of stories to summarize (top, new, best, all) [default: all]\n";
    echo "  --limit=N          Maximum number of stories to process per type [default: 100]\n";
    echo "  --db=PATH          Path to SQLite database [default: " . DB_PATH . "]\n";
    echo "  --output=PATH      Output directory for content files [default: " . OUTPUT_DIR . "]\n";
    echo "  --download-only    Download story and comments content without summarizing\n";
    echo "  --dry-run          Process and show what would be summarized without calling Gemini API\n";
    echo "  --story-id=ID      Process a single story by ID\n";
    echo "  --help, -h         Show this help message\n";
    exit(0);
}

$storyType = $options['type'] ?? 'all';
$limit = isset($options['limit']) ? (int)$options['limit'] : 10;
$dbPath = $options['db'] ?? DB_PATH;
$outputDir = $options['output'] ?? OUTPUT_DIR;
$downloadOnly = isset($options['download-only']);
$dryRun = isset($options['dry-run']);
$storyId = isset($options['story-id']) ? (int)$options['story-id'] : null;

print("storyType: {$storyType}\n");
print("limit: {$limit}\n");
if (isset($options['db'])) {
    print("dbPath: {$dbPath}\n");
}
if (isset($options['output'])) {
    print("outputDir: {$outputDir}\n");
}
if (isset($options['download-only'])) {
    print("downloadOnly\n");
}
if (isset($options['dry-run'])) {
    print("dryRun: {$dryRun}\n");
}
if (isset($options['story-id'])) {
    print("storyId: {$storyId}\n");
}

try {
    $summarizer = new StorySummarizer($dbPath, $outputDir, ORIGINALS_DIR, $dryRun);

    if ($storyId) {
        $summarizer->processStoryById($storyId, $downloadOnly);
    } else {
        if ($storyType === 'all') {
            // Process all story types with the limit applying to each type
            $types = array_keys(STORY_TYPES);
            
            foreach ($types as $type) {
                logMessage("=== Processing {$type} stories ===");
                $summarizer->run($type, $limit, $downloadOnly);
            }
            logMessage("=== Completed processing all story types ===");
        } else {
            // Process single story type
            $summarizer->run($storyType, $limit, $downloadOnly);
        }
    }

    // TODO: Delete stories, comments and summaries if they were fetched over 1 month ago
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

