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
    <section class="hero is-small">
        <div class="hero-body">
            <div class="container">
                <h1 class="subtitle is-3">
                    <?php echo htmlspecialchars($story['title']); ?>
                </h1>
                <p class="subtitle is-6">
                    <a href="<?php echo htmlspecialchars($story['url'] ?: $story['comments_url']); ?>" target="_blank">
                        <i class="fa-solid fa-external-link"></i> Original Story
                    </a>
                </p>
            </div>
        </div>
    </section>
    
    <section class="section">
        <div class="container">
            <div class="content markdown-body">
                <?php if ($has_story_summary): ?>
                <h2 class="title is-3">
                    <span class="icon"><i class="fa-regular fa-file-lines"></i></span>
                    Story Summary
                </h2>
                <div class="box">
                    <?php 
                    // Convert Markdown to HTML using Parsedown
                    $parsedown = new Parsedown();
                    echo $parsedown->text($story_summary);
                    ?>
                </div>
                <?php endif; ?>
                
                <?php if ($has_comments_summary): ?>
                <h2 class="title is-3">
                    <span class="icon"><i class="fa-regular fa-comments"></i></span>
                    Comments Summary
                </h2>
                <div class="box">
                    <?php 
                    // Convert Markdown to HTML using Parsedown
                    echo $parsedown->text($comments_summary);
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <footer class="footer">
        <div class="content has-text-centered">
            <p style="margin: 0; padding: 0;"><a href="https://emrahdiril.com">Emrah Diril</a> &copy; 2025</p>
            <p style="margin: 0; padding: 0;">100% coded with vibe</p>
        </div>
    </footer>
</body>
</html> 