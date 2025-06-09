<?php
// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Suppress deprecation warnings
error_reporting(E_ALL & ~E_DEPRECATED);

$page_title = 'Summary - ' . $story['title'];
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
                    <span><i class="fa-solid fa-arrow-up pl-2"></i> <?php echo (int)$story['score']; ?></span>
                    <span><i class="fa-solid fa-comment pl-2"></i> <?php echo (int)$story['comments_count']; ?></span>
                </div>
            </div>
        </div>
    </section>
    
    <section class="section summary-section py-6">
        <div class="container">
            <div class="content">
                <?php 
                // Initialize Parsedown once for use throughout the template
                $parsedown = new Parsedown();
                ?>
                
                <?php if ($has_story_summary): ?>
                <div class="box summary-card mb-6">
                    <h2 class="is-4 has-text-weight-bold mb-2">
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
                            <i class="fa-solid fa-external-link pl-2"></i> Comments
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
                        <div class="summary-content markdown-body px-1 pb-6">
                            Comments summary is not available.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
    
<?php include __DIR__ . '/partials/footer.php'; ?> 