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

// Hacker News API endpoints
const HN_BASE_URL = "https://hacker-news.firebaseio.com/v0";
const HN_ITEM_URL = HN_BASE_URL . "/item";

class StorySummarizer {
    private $dbPath;
    private $outputDir;
    private $originalsDir;
    private $client;
    private $model;
    
    public function __construct($dbPath = DB_PATH, $outputDir = OUTPUT_DIR, $originalsDir = ORIGINALS_DIR) {
        $this->dbPath = $dbPath;
        $this->outputDir = $outputDir;
        $this->originalsDir = $originalsDir;
        
        $apiKey = $_SERVER['GEMINI_API_KEY'] ?? null;
        if (!$apiKey) {
            throw new Exception('GEMINI_API_KEY environment variable not found');
        }
        
        // Initialize Gemini AI
        $this->client = Gemini::client($apiKey);
        $this->model = $this->client->generativeModel(model: 'gemini-2.0-flash-lite');
        
        // Create directories if they don't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
        if (!is_dir($this->originalsDir)) {
            mkdir($this->originalsDir, 0755, true);
        }
    }
    
    /**
     * Decode HTML entities in text to their corresponding characters
     */
    private function decodeHtmlEntities($text) {
        if (!$text) {
            return $text;
        }
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Fetch and extract main content from a story URL
     */
    private function fetchStoryContent($url) {
        if (!$url || strpos($url, "item?id=") !== false) {
            return "No URL provided or URL is a HN discussion.";
        }
        
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'timeout' => 10
                ]
            ]);
            
            $content = file_get_contents($url, false, $context);
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
            $content = file_get_contents($url);
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
        $content = file_get_contents($url);
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
    
    /**
     * Use Gemini AI to summarize content
     */
    private function summarize($content, $promptType) {
        try {
            // Define prompts based on content type
            if ($promptType === "story") {
                $prompt = str_replace('{content}', $content, STORY_PROMPT);
            } else { // comments
                $prompt = str_replace('{content}', $content, COMMENTS_PROMPT);
            }
            
            $result = $this->model->generateContent($prompt);
            return $result->text();
            
        } catch (Exception $e) {
            echo "ERROR: Gemini API error during summarization: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    /**
     * Process a single story: download content, summarize if needed
     */
    public function processStory($story, $downloadOnly = false) {
        echo "Processing story {$story['id']}\n";
        
        // Download story content
        $storyContent = "";
        $storyFile = $this->originalsDir . "/{$story['id']}-story.txt";
        if ($story['url'] && !file_exists($storyFile)) {
            echo "Fetching story...\n";
            $storyContent = $this->fetchStoryContent($story['url']);
            
            // Save original content
            if ($storyContent) {
                file_put_contents($storyFile, $storyContent);
                echo "Saved story...\n";
            }
        }
        
        // Download comments
        $commentsContent = "";
        $commentsFile = $this->originalsDir . "/{$story['id']}-comments.txt";
        if ($story['comments_url'] && !file_exists($commentsFile)) {
            echo "Fetching comments...\n";
            $commentsContent = $this->fetchComments($story['comments_url']);
            if ($commentsContent) {
                file_put_contents($commentsFile, $commentsContent);
                echo "Comments saved...\n";
            }
        }
        
        if ($downloadOnly) {
            echo "  Download-only mode: skipping summarization for story {$story['id']}\n";
            return;
        }
        
        // Summarize story content if we have it
        if (!$storyContent && file_exists($storyFile)) {
            $storyContent = file_get_contents($storyFile);
            echo "Loaded existing story content...\n";
        }
        
        $summaryFile = $this->outputDir . "/{$story['id']}-" . STORY_SUMMARY_FILE;
        if ($storyContent && !file_exists($summaryFile)) {
            echo "  Summarizing story content...\n";
            $startTime = microtime(true);
            
            $storySummary = $this->summarize($storyContent, "story");
            if ($storySummary) {
                $storySummary = $this->cleanupContent($storySummary);
                
                // Save the story summary
                file_put_contents($summaryFile, $storySummary);
                echo "  Saved story summary to {$summaryFile}\n";
                
                // Calculate and display elapsed time
                $elapsedTime = microtime(true) - $startTime;
                echo "  Story {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";

                // Delete the original story content
                //unlink($storyFile);
            }
        }
        
        // Generate comments summary if needed and we have comments
        if (!$commentsContent && file_exists($commentsFile)) {
            $commentsContent = file_get_contents($commentsFile);
            echo "Loaded existing comments content...\n";
        }
        
        if ($commentsContent && strlen($commentsContent) > 1000) {
            $commentsSummaryFile = $this->outputDir . "/{$story['id']}-" . COMMENTS_SUMMARY_FILE;
            if (!file_exists($commentsSummaryFile)) {
                echo "  Summarizing comments...\n";
                $startTime = microtime(true);
                
                $commentsSummary = $this->summarize($commentsContent, "comments");
                if ($commentsSummary) {
                    $commentsSummary = $this->cleanupContent($commentsSummary);
                    
                    // Save the comments summary
                    file_put_contents($commentsSummaryFile, $commentsSummary);
                    echo "  Saved comments summary to {$commentsSummaryFile}\n";
                    
                    // Calculate and display elapsed time
                    $elapsedTime = microtime(true) - $startTime;
                    echo "  Comments {$story['id']} summarized in " . number_format($elapsedTime, 2) . " sec\n";

                    // Delete the original comments content
                    //unlink($commentsFile);
                }
            }
        }
        
        // Sleep to be nice to APIs and servers
        sleep(1);
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
                SELECT id, title, url, domain, comments_url
                FROM stories
                WHERE type = ?
                ORDER BY ranking
            ');
            $stmt->execute([$storyType]);
            
            $storyCnt = 0;
            while ($story = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->processStory($story, $downloadOnly);
                
                $storyCnt++;
                if ($limit > 0 && $storyCnt == $limit) {
                    break;
                }
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
                SELECT id, title, url, domain, comments_url
                FROM stories
                WHERE id = ?
            ');
            $stmt->execute([$storyId]);
            
            $story = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($story) {
                $this->processStory($story, $downloadOnly);
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
        'story-id:',
        'help'
    ]);
    
    if (isset($options['help'])) {
        echo "Usage: php summarize.php [options]\n";
        echo "Options:\n";
        echo "  --type=TYPE        Type of stories to summarize (top, new, best) [default: top]\n";
        echo "  --limit=N          Maximum number of stories to process [default: 10]\n";
        echo "  --db=PATH          Path to SQLite database [default: " . DB_PATH . "]\n";
        echo "  --output=PATH      Output directory for content files [default: " . OUTPUT_DIR . "]\n";
        echo "  --download-only    Download story and comments content without summarizing\n";
        echo "  --story-id=ID      Process a single story by ID\n";
        echo "  --help             Show this help message\n";
        exit(0);
    }
    
    $storyType = $options['type'] ?? 'top';
    $limit = isset($options['limit']) ? (int)$options['limit'] : 10;
    $dbPath = $options['db'] ?? DB_PATH;
    $outputDir = $options['output'] ?? OUTPUT_DIR;
    $downloadOnly = isset($options['download-only']);
    $storyId = isset($options['story-id']) ? (int)$options['story-id'] : null;
    
    $summarizer = new StorySummarizer($dbPath, $outputDir);
    
    if ($storyId) {
        $summarizer->processStoryById($storyId, $downloadOnly);
    } else {
        $summarizer->run($storyType, $limit, $downloadOnly);
    }
} else {
    echo "Story Summarizer\n";
    echo "Usage: php summarize.php --help for options\n";
    echo "Example: php summarize.php --type=top --limit=5\n";
}

