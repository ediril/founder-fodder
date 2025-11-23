<?php
$page_title = htmlspecialchars($story_name) . ' - Inspiration - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>

<section class="section pt-5 pb-0">
    <div class="container">
        <h1 class="title is-2 is-size-4-mobile has-text-centered">
            The <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $story_name))); ?> Story
        </h1>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="carousel-wrapper">
            <button class="button is-light" id="prev-btn" onclick="navigate(-1)" aria-label="Previous">
                <span class="icon">
                    <i class="fas fa-chevron-left"></i>
                </span>
            </button>

            <div class="carousel-container">
                <div class="box p-0 has-shadow" style="background: #000; box-shadow: 0 8px 16px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05);">
                    <figure class="image">
                        <img id="carousel-image" src="" alt="<?php echo htmlspecialchars($story_name); ?>">
                    </figure>
                </div>
            </div>

            <button class="button is-light" id="next-btn" onclick="navigate(1)" aria-label="Next">
                <span class="icon">
                    <i class="fas fa-chevron-right"></i>
                </span>
            </button>
        </div>

        <div class="has-text-centered mt-4">
            <button class="button is-light mobile-nav-buttons" onclick="navigate(-1)" aria-label="Previous">
                <span class="icon">
                    <i class="fas fa-chevron-left"></i>
                </span>
            </button>

            <span class="tag is-light is-medium" id="counter">
                <span id="current-page">1</span> / <span id="total-pages"><?php echo $page_count; ?></span>
            </span>

            <button class="button is-light mobile-nav-buttons" onclick="navigate(1)" aria-label="Next">
                <span class="icon">
                    <i class="fas fa-chevron-right"></i>
                </span>
            </button>
        </div>

        <?php if (!empty($story_text_blocks)): ?>
        <div class="box mt-6">
            <div class="content is-medium">
                <?php foreach ($story_text_blocks as $text_block): ?>
                    <p><?php echo htmlspecialchars(str_replace("\n", " ", $text_block)); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($adjacent_stories)): ?>
        <div class="box mt-5">
            <h3 class="title is-5">Did you read these stories?</h3>
            <div class="content">
                <div class="columns is-mobile">
                    <?php foreach ($adjacent_stories as $adjacent): ?>
                    <div class="column">
                        <a href="/<?php echo htmlspecialchars($adjacent['slug']); ?>" class="adjacent-story-link box has-text-centered">
                            <div style="margin-bottom: 1rem;">
                                <?php
                                $image_path = "inspire/{$adjacent['slug']}.jpg";
                                if (file_exists($image_path)):
                                    $image_version = filemtime($image_path);
                                    $image_url = "/" . $image_path . "?v=" . $image_version;
                                ?>
                                <figure class="image is-128x128" style="margin: 0 auto;">
                                    <img src="<?php echo htmlspecialchars($image_url); ?>"
                                        alt="<?php echo htmlspecialchars($adjacent['name']); ?>"
                                        class="adjacent-story-image"
                                        onerror="this.style.display='none'; this.parentNode.innerHTML = '<div style=\'width: 128px; height: 128px; border-radius: 50%; background: #f5f5f5; display: flex; align-items: center; justify-content: center; margin: 0 auto;\'><i class=\'fa-solid fa-lightbulb fa-3x has-text-grey-light\'></i></div>';">
                                </figure>
                                <?php else: ?>
                                <div style="width: 128px; height: 128px; border-radius: 50%; background: #f5f5f5; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                                    <i class="fa-solid fa-lightbulb fa-3x has-text-grey-light"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <p class="has-text-weight-semibold has-text-dark"><?php echo htmlspecialchars($adjacent['name']); ?></p>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($references)): ?>
        <div class="box mt-5">
            <h3 class="title is-5">References</h3>
            <div class="content is-medium">
                <ol>
                    <?php foreach ($references as $reference): ?>
                        <?php
                        // Extract URL from reference text
                        if (preg_match('/(.*?)\s*(https?:\/\/[^\s]+)$/i', $reference, $matches)) {
                            $title = trim($matches[1]);
                            $url = trim($matches[2]);
                            echo '<li><a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($title) . '</a></li>';
                        } else {
                            // Fallback if no URL pattern found
                            echo '<li>' . htmlspecialchars($reference) . '</li>';
                        }
                        ?>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
const storyName = <?php echo json_encode($story_name); ?>;
const pageCount = <?php echo $page_count; ?>;
let currentPage = 0;

const imageElement = document.getElementById('carousel-image');
const currentPageElement = document.getElementById('current-page');
const prevBtn = document.getElementById('prev-btn');
const nextBtn = document.getElementById('next-btn');

function updateImage() {
    <?php if (isset($show_generation_modal) && $show_generation_modal): ?>
    // During generation, show a placeholder or nothing
    imageElement.style.visibility = 'hidden';
    <?php else: ?>
    // Add cache busting parameter based on file modification time
    const cacheBuster = '<?php
        // Get latest mtime from all page images for cache busting
        $story_dir = __DIR__ . "/../inspire/" . $story_name;
        $latest_mtime = 0;
        if (is_dir($story_dir)) {
            $page_files = glob($story_dir . "/page_*.jpg");
            foreach ($page_files as $file) {
                $mtime = filemtime($file);
                if ($mtime > $latest_mtime) {
                    $latest_mtime = $mtime;
                }
            }
        }
        echo $latest_mtime;
    ?>';
    imageElement.src = `/inspire/${storyName}/page_${currentPage}.jpg?v=${cacheBuster}`;
    imageElement.style.visibility = 'visible';
    <?php endif; ?>

    // Update counter
    currentPageElement.textContent = currentPage + 1;

    // Update all navigation button states (desktop and mobile)
    const allPrevButtons = document.querySelectorAll('button[aria-label="Previous"]');
    const allNextButtons = document.querySelectorAll('button[aria-label="Next"]');

    allPrevButtons.forEach(btn => btn.disabled = currentPage === 0);
    allNextButtons.forEach(btn => btn.disabled = currentPage === pageCount - 1);
}

function navigate(direction) {
    const newPage = currentPage + direction;

    if (newPage >= 0 && newPage < pageCount) {
        currentPage = newPage;
        updateImage();
    }
}

// Keyboard navigation
document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') {
        navigate(-1);
    } else if (e.key === 'ArrowRight') {
        navigate(1);
    }
});

// Touch swipe support
let touchStartX = 0;
let touchEndX = 0;

imageElement.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
});

imageElement.addEventListener('touchend', (e) => {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
});

function handleSwipe() {
    const swipeThreshold = 50;
    const diff = touchStartX - touchEndX;

    if (Math.abs(diff) > swipeThreshold) {
        if (diff > 0) {
            navigate(1); // Swipe left = next
        } else {
            navigate(-1); // Swipe right = prev
        }
    }
}

// Initialize
updateImage();

<?php if (isset($show_generation_modal) && $show_generation_modal): ?>
// Show generation modal
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('generation-modal');
    modal.classList.add('is-active');

    // Start generation via hidden iframe
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = window.location.pathname + '?gen=1';
    document.body.appendChild(iframe);

    // Poll for completion
    let pollCount = 0;
    const maxPolls = 120; // 2 minutes max (120 * 1000ms)

    const pollInterval = setInterval(function() {
        pollCount++;

        // Check if page_0.jpg exists by trying to load it
        const img = new Image();
        img.onload = function() {
            // Image exists, generation complete
            clearInterval(pollInterval);
            // Reload page to show generated content
            window.location.reload();
        };
        img.onerror = function() {
            // Image doesn't exist yet, keep polling
            if (pollCount >= maxPolls) {
                clearInterval(pollInterval);
                document.getElementById('generation-message').textContent = 'Generation is taking longer than expected. Please refresh the page.';
            }
        };
        img.src = '/inspire/<?php echo htmlspecialchars($story_name); ?>/page_0.jpg?' + Date.now();
    }, 1000); // Poll every second
});
<?php endif; ?>
</script>

<?php if (isset($show_generation_modal) && $show_generation_modal): ?>
<!-- Generation Modal -->
<div class="modal" id="generation-modal">
    <div class="modal-background"></div>
    <div class="modal-content">
        <div class="box has-text-centered" style="max-width: 500px; margin: 0 auto;">
            <div style="display: flex; flex-direction: column; align-items: center; gap: 1.5rem; padding: 2rem 1rem;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #3273dc; border-radius: 50%; width: 60px; height: 60px; animation: spin 1s linear infinite;"></div>
                <div>
                    <h2 class="title is-4">Preparing <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $story_name))); ?>'s story...</h2>
                    <p class="has-text-grey" id="generation-message">This will take a minute. Stay put.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
