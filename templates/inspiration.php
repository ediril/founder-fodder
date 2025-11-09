<?php
$page_title = 'Inspiration - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>
<section class="section">
    <div class="container">
        <?php if (empty($people)): ?>
        <div class="notification is-warning">
            No stories available yet.
        </div>
        <?php else: ?>

        <div class="story-list">
            <?php foreach ($people as $person): ?>
            <div class="story-card">
                <div class="story-image">
                    <?php
                    $image_path = "inspire/{$person['slug']}.jpg";
                    if (file_exists($image_path)):
                    ?>
                    <img src="/<?php echo htmlspecialchars($image_path); ?>"
                        alt="<?php echo htmlspecialchars($person['name']); ?>"
                        onerror="this.style.display='none'; this.parentNode.innerHTML = '<div class=\'image-placeholder\'><i class=\'fa-solid fa-lightbulb\'></i></div>';">
                    <?php else: ?>
                    <div class="image-placeholder">
                        <i class="fa-solid fa-lightbulb"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="story-content">
                    <p class="title is-5 mb-3">
                        <a href="/<?php echo htmlspecialchars($person['slug']); ?>">
                            <?php echo htmlspecialchars($person['name']); ?>
                        </a>
                    </p>
                    <?php
                    // Load first paragraph from yml file as excerpt
                    $yml_file = __DIR__ . '/../inspire/' . $person['slug'] . '.yml';
                    $excerpt = "";
                    if (file_exists($yml_file)) {
                        $yml_content = file_get_contents($yml_file);
                        // Extract first text block (text_0)
                        if (preg_match('/- text_0:\s*\n\s*-\s*"([^"]+)"/', $yml_content, $matches)) {
                            $excerpt = $matches[1];
                            // Replace em dashes, en dashes, and regular dashes with spaces
                            $excerpt = str_replace(['—', '–', '-'], ' ', $excerpt);
                            if (strlen($excerpt) > 200) {
                                $excerpt = substr($excerpt, 0, 200) . '...';
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($excerpt)): ?>
                    <p class="story-excerpt mb-3">
                        <?php echo htmlspecialchars($excerpt); ?>
                    </p>
                    <?php endif; ?>
                    <div class="story-actions">
                        <p>
                            <a class="button is-small mt-2"
                                href="/<?php echo htmlspecialchars($person['slug']); ?>">Get Inspired</a>
                        </p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
