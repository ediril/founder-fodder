<?php
$page_title = 'Inspiration - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>
<section class="section">
    <div class="container">
        <div class="content is-medium">
            <div class="box">
                <h1 class="title is-3">Inspirational Stories</h1>
                <?php if (empty($people)): ?>
                    <p class="has-text-grey">No stories available yet.</p>
                <?php else: ?>
                    <div class="content">
                        <ul>
                            <?php foreach ($people as $person): ?>
                                <li>
                                    <a href="/<?php echo htmlspecialchars($person['slug']); ?>">
                                        <?php echo htmlspecialchars($person['name']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>
