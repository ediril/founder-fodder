<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Founder Fodder</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/static/css/site.css">
</head>
<body>
    <section class="hero is-small">
        <div class="hero-body">
            <div class="container has-text-centered">
                <h1 class="title is-2">
                    <i class="fa-solid fa-fire"></i> Founder Fodder
                </h1>
                <p class="subtitle">
                    Inspiration for Founders, Makers and Tinkerers
                </p>
            </div>
        </div>
    </section>
    
    <section class="section">
        <div class="container">
            <div class="buttons story-filters">
                <a href="/?type=top" class="button <?php echo ($stories['type'] === 'top') ? 'is-primary' : ''; ?>">
                    <span class="icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                    <span>Top</span>
                </a>
                <a href="/?type=new" class="button <?php echo ($stories['type'] === 'new') ? 'is-primary' : ''; ?>">
                    <span class="icon"><i class="fa-solid fa-clock"></i></span>
                    <span>New</span>
                </a>
                <a href="/?type=best" class="button <?php echo ($stories['type'] === 'best') ? 'is-primary' : ''; ?>">
                    <span class="icon"><i class="fa-solid fa-star"></i></span>
                    <span>Best</span>
                </a>
            </div>
            
            <?php if (empty($stories['stories'])): ?>
            <div class="notification is-warning">
                No stories found.
            </div>
            <?php else: ?>
            
            <div class="story-list">
                <?php foreach ($stories['stories'] as $story): ?>
                <div class="story-card">
                    <div class="story-image">
                        <?php if (!empty($story['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($story['image_url']); ?>" 
                            alt="<?php echo htmlspecialchars($story['domain']); ?>"
                            onerror="this.style.display='none'; this.parentNode.innerHTML = '<div class=\'image-placeholder\'><i class=\'fa-solid fa-fire\'></i></div>';">
                        <?php else: ?>
                        <div class="image-placeholder">
                            <i class="fa-solid fa-fire"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="story-content">
                        <p class="title is-5">
                            <a href="<?php echo htmlspecialchars($story['url']); ?>">
                                <?php echo htmlspecialchars($story['title']); ?>
                            </a>
                        </p>
                        <p class="subtitle is-6">
                            <span><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($story['time_ago']); ?></span>
                            <span><i class="fas fa-globe pl-2"></i> <?php echo htmlspecialchars($story['domain']); ?></span>
                            <span><i class="fa-solid fa-arrow-up pl-2"></i> <?php echo (int)$story['score']; ?></span>
                        </p>
                        <div class="story-actions">
                            <a href="<?php echo htmlspecialchars($story['comments_url']); ?>" class="button is-small pl-3">
                                <span class="icon"><i class="fa-regular fa-comment"></i></span>
                                <span><?php echo (int)$story['comments_count']; ?></span>
                            </a>
                            <?php 
                            $summary_files = $story['summary_files'] ?? ['has_story_summary' => false, 'has_comments_summary' => false];
                            if ($summary_files['has_story_summary'] || $summary_files['has_comments_summary']): 
                            ?>
                            <a href="/summary/<?php echo htmlspecialchars($story['id']); ?>" class="button is-small pl-3">
                                <span class="icon"><i class="fa-regular fa-file-lines"></i></span>
                                <span>Summary</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($stories['pagination']['has_more']): ?>
            <div class="pagination-wrapper has-text-centered mt-5">
                <a href="/?type=<?php echo htmlspecialchars($stories['type']); ?>&page=<?php echo (int)$stories['pagination']['next_page']; ?>" 
                   class="button is-primary is-medium">
                    <span>More Stories</span>
                    <span class="icon">
                        <i class="fa-solid fa-arrow-down"></i>
                    </span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </section>
    
    <footer class="footer">
        <div class="content has-text-centered">
            <p style="margin: 0; padding: 0;"><a href="https://emrahdiril.com">Emrah Diril</a> &copy; 2025</p>
            <p style="margin: 0; padding: 0;">Created with ✨ & ❤️</p>
        </div>
    </footer>
</body>
</html>