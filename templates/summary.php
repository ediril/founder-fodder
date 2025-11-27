<?php
// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Suppress deprecation warnings
error_reporting(E_ALL & ~E_DEPRECATED);

$page_title = 'Summary - ' . $story['title'];

// Set Open Graph data for story summary
$og_title = $story['title'] . ' - Summary | Founder Fodder';
$og_type = 'article';

// Generate description from summary content
$og_description = 'Read summary and key insights from this story';
if ($has_story_summary && !empty($story_summary)) {
    // Extract first paragraph from markdown summary for description
    $lines = explode("\n", $story_summary);
    $first_paragraph = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && !str_starts_with($line, '#') && !str_starts_with($line, '##')) {
            $first_paragraph = $line;
            break;
        }
    }
    if (!empty($first_paragraph)) {
        // Clean up markdown and truncate
        $first_paragraph = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $first_paragraph); // remove markdown links
        $first_paragraph = preg_replace('/[*_`#>-]/', '', $first_paragraph); // remove markdown formatting
        $og_description = strlen($first_paragraph) > 160 ? substr($first_paragraph, 0, 157) . '...' : $first_paragraph;
    }
}

// Check if story has an image for Open Graph
$og_image = '';
$og_image_alt = '';
$image_extensions = ['jpg', 'png', 'webp'];
foreach ($image_extensions as $ext) {
    $img_path = __DIR__ . "/../static/img/stories/{$story['id']}.{$ext}";
    if (file_exists($img_path)) {
        $og_image = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                   '://' . $_SERVER['HTTP_HOST'] . getBasePath() . "/static/img/stories/{$story['id']}.{$ext}";
        $og_image_alt = $story['title'];
        break;
    }
}

$additional_css = ['https://cdn.jsdelivr.net/npm/github-markdown-css/github-markdown.css'];
include __DIR__ . '/partials/head.php'; 
?>
    <section class="hero is-small summary-hero">
        <div class="hero-body">
            <div class="container">
                <a href="javascript:history.back()" class="back-button mb-2">
                    <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
                    <span>Back</span>
                </a>
                <h1 class="summary-title">
                    <?php echo htmlspecialchars($story['title']); ?>
                </h1>
                <div class="subtitle is-6 pt-1 mb-2">
                    <span><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($story['time_ago']); ?></span>
                    <span><i class="fas fa-globe pl-2"></i> <?php echo htmlspecialchars($story['domain']); ?></span>
                </div>
            </div>
        </div>
    </section>
    
    <section class="section summary-section py-4 px-3">
        <div class="container">
            <div class="content">
                <?php 
                // Initialize Parsedown once for use throughout the template
                $parsedown = new Parsedown();
                ?>
                
                <?php if ($has_story_summary): ?>
                <div class="box summary-card mb-6 p-2">
                    <h2 class="is-4 has-text-weight-bold my-2">
                        <span class="icon has-text-primary"><i class="fa-regular fa-file-lines"></i></span>
                        Story Summary
                        <a class="is-size-5" href="<?php echo htmlspecialchars($story['url']); ?>">
                            <i class="fa-solid fa-external-link pl-2"></i> Story
                        </a>
                    </h2>
                    <div class="summary-last-updated pl-1 py-3">                        
                        <p class="has-text-grey-light">Last updated: <?php echo htmlspecialchars($story_summary_last_updated); ?></p>
                    </div>
                    <div class="summary-content markdown-body px-1 pb-6">
                        <?php 
                        // Convert Markdown to HTML using Parsedown
                        echo $parsedown->text($story_summary);
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="box summary-card">
                    <h2 class="is-4 has-text-weight-bold">
                        <span class="icon has-text-primary"><i class="fa-regular fa-comments"></i></span>
                        Comments Summary
                        <a class="is-size-5" href="<?php echo htmlspecialchars($story['comments_url']); ?>">
                            <i class="fa-solid fa-external-link pl-2"></i> Comments (<?php echo $story['comments_count']; ?>)
                        </a>
                    </h2>
                    <?php if ($has_comments_summary): ?>
                        <div class="summary-last-updated pl-1 py-3">                        
                            <p class="has-text-grey-light">Last updated: <?php echo htmlspecialchars($comments_summary_last_updated); ?></p>
                        </div>
                        <div class="summary-content markdown-body px-1 pb-6">
                            <?php 
                            // Convert Markdown to HTML using Parsedown
                            echo $parsedown->text($comments_summary);
                            ?>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="summary-content px-1 pb-6">
                            <?php if ($story['comments_count'] > 10): ?>
                                <div id="comments-summary-container" class="has-text-centered">
                                    <br><br>
                                    <?php if ($has_pending_request): ?>
                                        <!-- Show queued state with clock icon -->
                                        <div id="summary-loading">
                                            <div class="notification is-info">
                                                <span class="icon">
                                                    <i class="fa-solid fa-clock"></i>
                                                </span>
                                                <p>Your request is queued and will be processed shortly.</p>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Show button for new requests -->
                                        <button id="summarize-comments-btn" class="button is-primary" data-story-id="<?php echo $story['id']; ?>">
                                            <span class="icon">
                                                <i class="fa-solid fa-magic-wand-sparkles"></i>
                                            </span>
                                            <span>Summarize Comments</span>
                                        </button>
                                        <div id="summary-loading" class="is-hidden mt-4">
                                            <div class="notification is-info">
                                                <span class="icon">
                                                    <i class="fa-solid fa-spinner fa-spin"></i>
                                                </span>
                                                <p>Generating comment summary... This may take a moment.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <div id="summary-error" class="is-hidden mt-4">
                                        <div class="notification is-danger">
                                            <span id="error-message"></span>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="notification is-light">
                                    Not enough comments to summarize (<?php echo $story['comments_count']; ?> comments available).
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    
<?php include __DIR__ . '/partials/footer.php'; ?> 