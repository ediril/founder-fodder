<?php
$page_title = 'Loading Story - Founder Fodder';
include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/hero.php';
?>

<style>
.loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 400px;
    padding: 3rem 1.5rem;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3273dc;
    border-radius: 50%;
    width: 60px;
    height: 60px;
    animation: spin 1s linear infinite;
    margin-bottom: 2rem;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.loading-message {
    text-align: center;
}
</style>

<section class="section">
    <div class="container">
        <div class="box">
            <div class="loading-container">
                <div class="spinner"></div>
                <div class="loading-message">
                    <h2 class="title is-4">Preparing <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $story_name))); ?>'s story...</h2>
                    <p class="has-text-grey">This will take a minute. Stay put.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
// Redirect to trigger generation after 2 seconds
setTimeout(function() {
    window.location.href = window.location.pathname + '?gen=1';
}, 2000);
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
