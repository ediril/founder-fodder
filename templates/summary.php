<?php
// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Suppress deprecation warnings
error_reporting(E_ALL & ~E_DEPRECATED);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Summary - <?php echo htmlspecialchars($story['title']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/static/css/site.css">
    <!-- Add Markdown CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/github-markdown-css/github-markdown.css">
</head>
<body>
    <section class="hero is-small summary-hero">
        <div class="hero-body">
            <div class="container">
                <a href="/" class="back-button">
                    <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
                    <span>Back to Stories</span>
                </a>
                <h1 class="title is-2 has-text-white">
                    <?php echo htmlspecialchars($story['title']); ?>
                </h1>
                <h2 class="subtitle is-6 pt-3 has-text-white">
                    <a href="<?php echo htmlspecialchars($story['url']); ?>" class="has-text-white">
                        <i class="fa-solid fa-external-link"></i> Original Story
                    </a>
                    <a href="<?php echo htmlspecialchars($story['comments_url']); ?>" class="has-text-white">
                        <i class="fa-solid fa-external-link pl-2"></i> HN Comments
                    </a>
                </h2>
            </div>
        </div>
    </section>
    
    <section class="section summary-section">
        <div class="container">
            <div class="content">
                <?php if ($has_story_summary): ?>
                <div class="box summary-card">
                    <h2 class="title is-4 has-text-weight-bold">
                        <span class="icon has-text-primary"><i class="fa-regular fa-file-lines"></i></span>
                        Story Summary
                    </h2>
                    <div class="summary-content markdown-body">
                        <?php 
                        // Convert Markdown to HTML using Parsedown
                        $parsedown = new Parsedown();
                        echo $parsedown->text($story_summary);
                        ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($has_comments_summary): ?>
                <div class="box summary-card">
                    <h2 class="title is-4 has-text-weight-bold">
                        <span class="icon has-text-primary"><i class="fa-regular fa-comments"></i></span>
                        Comments Summary
                    </h2>
                    <div class="summary-content markdown-body">
                        <?php 
                        // Convert Markdown to HTML using Parsedown
                        echo $parsedown->text($comments_summary);
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <footer class="footer">
        <div class="content has-text-centered">
            <p><a href="https://emrahdiril.com">Emrah Diril</a> &copy; 2025</p>
            <p>100% coded with vibe</p>
        </div>
    </footer>
</body>
</html> 