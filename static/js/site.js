/**
 * Dark Mode Manager
 * Handles automatic dark mode switching based on system preferences
 * and allows manual toggling with preference persistence
 */
class DarkModeManager {
    constructor() {
        this.storageKey = 'founder-fodder-theme';
        this.init();
    }

    init() {
        // Load saved preference or detect system preference
        const savedTheme = this.getSavedTheme();
        const systemPrefersDark = this.getSystemPreference();
        
        // Set initial theme
        if (savedTheme) {
            this.setTheme(savedTheme);
        } else if (systemPrefersDark) {
            this.setTheme('dark');
        } else {
            this.setTheme('light');
        }

        // Listen for system preference changes
        this.watchSystemPreference();
        
        // Create toggle button after theme is set
        this.createToggleButton();
        
        // Ensure button shows correct state
        this.updateToggleButton();
    }

    getSystemPreference() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    getSavedTheme() {
        try {
            return localStorage.getItem(this.storageKey);
        } catch (e) {
            console.log('LocalStorage not available:', e);
            return null;
        }
    }

    saveTheme(theme) {
        try {
            if (theme === 'system') {
                localStorage.removeItem(this.storageKey);
            } else {
                localStorage.setItem(this.storageKey, theme);
            }
        } catch (e) {
            console.log('LocalStorage not available:', e);
        }
    }

    setTheme(theme) {
        const root = document.documentElement;
        
        if (theme === 'dark') {
            root.setAttribute('data-theme', 'dark');
        } else if (theme === 'light') {
            root.setAttribute('data-theme', 'light');
        } else if (theme === 'system') {
            root.removeAttribute('data-theme');
        }
        
        this.currentTheme = theme;
        this.updateToggleButton();
    }

    getCurrentTheme() {
        const savedTheme = this.getSavedTheme();
        if (savedTheme) {
            return savedTheme;
        }
        
        return this.getSystemPreference() ? 'dark' : 'light';
    }

    toggleTheme() {
        const isDark = this.isCurrentlyDark();
        const newTheme = isDark ? 'light' : 'dark';
        
        this.setTheme(newTheme);
        this.saveTheme(newTheme);
    }

    resetToSystem() {
        this.setTheme('system');
        this.saveTheme('system');
    }

    watchSystemPreference() {
        if (window.matchMedia) {
            const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            
            // Modern browsers
            if (mediaQuery.addEventListener) {
                mediaQuery.addEventListener('change', (e) => {
                    // Only update if user hasn't set a manual preference
                    if (!this.getSavedTheme()) {
                        this.setTheme(e.matches ? 'dark' : 'light');
                    }
                });
            } else if (mediaQuery.addListener) {
                // Older browsers
                mediaQuery.addListener((e) => {
                    if (!this.getSavedTheme()) {
                        this.setTheme(e.matches ? 'dark' : 'light');
                    }
                });
            }
        }
    }

    createToggleButton() {
        // Check if button already exists
        if (document.querySelector('.dark-mode-toggle')) {
            return;
        }

        const button = document.createElement('button');
        button.className = 'dark-mode-toggle';
        button.setAttribute('aria-label', 'Toggle dark mode');
        button.setAttribute('title', 'Toggle dark mode');
        
        this.toggleButton = button;

        // Add click handler
        button.addEventListener('click', (e) => {
            e.preventDefault();
            this.toggleTheme();
        });

        // Add keyboard handler
        button.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.toggleTheme();
            }
        });

        // Add to page
        document.body.appendChild(button);
    }

    updateToggleButton() {
        if (!this.toggleButton) return;

        // Determine if dark mode is actually active
        const isDark = this.isCurrentlyDark();
        
        this.toggleButton.innerHTML = isDark 
            ? '<i class="fa-solid fa-moon"></i>'
            : '<i class="fa-solid fa-sun"></i>';
        
        this.toggleButton.setAttribute('aria-pressed', isDark.toString());
    }

    isCurrentlyDark() {
        const root = document.documentElement;
        const dataTheme = root.getAttribute('data-theme');
        
        // If explicitly set to dark
        if (dataTheme === 'dark') {
            return true;
        }
        
        // If explicitly set to light
        if (dataTheme === 'light') {
            return false;
        }
        
        // If no explicit theme set, check system preference
        return this.getSystemPreference();
    }

    // Public API methods
    static getInstance() {
        if (!window.darkModeManager) {
            window.darkModeManager = new DarkModeManager();
        }
        return window.darkModeManager;
    }
}

// Auto-initialize when DOM is ready
function initDarkMode() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            DarkModeManager.getInstance();
        });
    } else {
        DarkModeManager.getInstance();
    }
}

// Initialize immediately
initDarkMode();

// Export for manual use if needed
window.DarkModeManager = DarkModeManager;

/**
 * Comment Summarization Manager
 * Handles on-demand comment summarization via AJAX
 */
class CommentSummarizationManager {
    constructor() {
        this.init();
    }

    init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.bindEvents();
            });
        } else {
            this.bindEvents();
        }
    }

    bindEvents() {
        const button = document.getElementById('summarize-comments-btn');
        if (button) {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleSummarizeClick(button);
            });
        }

        // Check if there's a pending request on page load - start polling
        const loadingDiv = document.getElementById('summary-loading');
        if (loadingDiv && !loadingDiv.classList.contains('is-hidden')) {
            const storyIdMatch = window.location.pathname.match(/\/summary\/(\d+)/);
            if (storyIdMatch) {
                const storyId = storyIdMatch[1];
                console.log('Pending request detected on page load, starting polling for story', storyId);
                this.startPolling(storyId);
            }
        }
    }

    async handleSummarizeClick(button) {
        const storyId = button.getAttribute('data-story-id');
        if (!storyId) {
            this.showError('Story ID not found');
            return;
        }

        // Disable button immediately to prevent multiple clicks
        button.disabled = true;
        button.textContent = 'Processing...';

        // Show loading state
        this.showLoading(button);

        try {
            // Get CSRF token from meta tag
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            const response = await fetch(`/create-comment-request/${storyId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken || '',
                },
            });

            const result = await response.json();

            // Log debug information
            console.log('Summarizer response:', result);
            if (result.debug) {
                console.log('Debug info:', result.debug);
            }

            if (response.status === 429) {
                // Server-side rate limiting
                const remainingTime = result.remaining_seconds || 15;
                this.showToast(`Please wait ${remainingTime} seconds before requesting again.`, 'warning');
                this.showButton(); // Show button again
                return;
            }

            if (result.success) {
                console.log('Summarizer started in background');

                // Show queued message and start polling
                this.showQueuedMessage();
                this.startPolling(storyId);
            } else {
                console.error('Summarizer failed:', result.error);
                if (result.debug) {
                    console.error('Exit code:', result.debug.exit_code);
                    console.error('STDOUT:', result.debug.stdout);
                    console.error('STDERR:', result.debug.stderr);
                }

                // Show detailed error to user
                let errorMessage = result.error || 'Failed to start summary generation';
                if (result.debug && result.debug.stderr) {
                    errorMessage += '\n\nDetails: ' + result.debug.stderr;
                }

                this.showError(errorMessage);
                this.showButton(); // Show button again on error
            }
        } catch (error) {
            console.error('Error starting summary generation:', error);
            this.showError('Network error occurred. Please try again.');
            this.showButton(); // Show button again on error
        }
    }

    showLoading(button) {
        // Hide button
        button.style.display = 'none';

        // Show loading indicator
        const loadingDiv = document.getElementById('summary-loading');
        if (loadingDiv) {
            loadingDiv.classList.remove('is-hidden');
        }

        // Hide any previous errors
        this.hideError();
    }

    showSummary(summaryMarkdown, lastUpdated) {
        // Hide loading
        this.hideLoading();

        // Create summary HTML structure
        const container = document.getElementById('comments-summary-container');
        if (!container) return;

        // Convert markdown to HTML (simple implementation for now)
        const summaryHtml = this.markdownToHtml(summaryMarkdown);

        container.innerHTML = `
            <div class="summary-last-updated pl-1 py-3">
                <p class="has-text-grey-light">Last updated: ${this.escapeHtml(lastUpdated)}</p>
            </div>
            <div class="summary-content markdown-body px-1 pb-6">
                ${summaryHtml}
            </div>
        `;
    }

    showError(errorMessage) {
        // Hide loading
        this.hideLoading();

        // Show error
        const errorDiv = document.getElementById('summary-error');
        const errorMessageSpan = document.getElementById('error-message');

        if (errorDiv && errorMessageSpan) {
            errorMessageSpan.textContent = errorMessage;
            errorDiv.classList.remove('is-hidden');
        }

        // Show button again
        const button = document.getElementById('summarize-comments-btn');
        if (button) {
            button.style.display = '';
        }
    }

    hideLoading() {
        const loadingDiv = document.getElementById('summary-loading');
        if (loadingDiv) {
            loadingDiv.classList.add('is-hidden');
        }
    }

    hideError() {
        const errorDiv = document.getElementById('summary-error');
        if (errorDiv) {
            errorDiv.classList.add('is-hidden');
        }
    }

    showButton() {
        const button = document.getElementById('summarize-comments-btn');
        if (button) {
            button.style.display = '';
            button.disabled = false;
            button.textContent = 'Summarize Comments';
        }
    }

    showQueuedMessage() {
        // Hide button
        const button = document.getElementById('summarize-comments-btn');
        if (button) {
            button.style.display = 'none';
        }

        // Hide loading spinner
        this.hideLoading();

        // Hide any errors
        this.hideError();

        // Show queued message with clock icon
        const loadingDiv = document.getElementById('summary-loading');
        if (loadingDiv) {
            loadingDiv.innerHTML = `
                <div class="notification is-info">
                    <span class="icon">
                        <i class="fa-solid fa-clock"></i>
                    </span>
                    <p>Your request is queued and will be processed shortly.</p>
                </div>
            `;
            loadingDiv.classList.remove('is-hidden');
        }
    }

    showToast(message, type = 'info') {
        // Create toast notification using Bulma notification classes
        const toast = document.createElement('div');
        toast.className = `notification is-${type} toast-notification`;
        toast.innerHTML = `
            <button class="delete" onclick="this.parentElement.remove()"></button>
            ${message}
        `;

        // Add CSS for toast positioning if not already added
        if (!document.getElementById('toast-styles')) {
            const style = document.createElement('style');
            style.id = 'toast-styles';
            style.textContent = `
                .toast-notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    max-width: 400px;
                    animation: slideInRight 0.3s ease;
                }
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
        }

        document.body.appendChild(toast);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 5000);
    }

    startPolling(storyId) {
        // Store polling state
        this.pollStoryId = storyId;
        this.pollAttempts = 0;
        this.maxPollAttempts = 20;
        this.consecutiveErrors = 0;
        this.maxConsecutiveErrors = 5;

        // Start the polling
        this.pollInterval = setInterval(() => {
            this.checkSummaryStatus(storyId);
        }, 3000); // Poll every 3 seconds
    }

    async checkSummaryStatus(storyId) {
        this.pollAttempts++;

        try {
            // Check if summary is ready by looking for the button vs summary content
            const response = await fetch(`/summary/${storyId}`);
            const html = await response.text();

            // Reset error counter on successful fetch
            this.consecutiveErrors = 0;

            // Check if summary is ready by looking for actual summary content
            // We need to be more specific - check if markdown-body appears AFTER "Comments Summary"
            // This prevents false positives from story summary's markdown-body
            const commentsHeadingIndex = html.indexOf('Comments Summary');
            const hasCommentSummaryHeading = commentsHeadingIndex !== -1;

            // Look for markdown-body only AFTER the Comments Summary heading
            let hasMarkdownContent = false;
            if (hasCommentSummaryHeading) {
                const htmlAfterCommentsHeading = html.substring(commentsHeadingIndex);
                hasMarkdownContent = htmlAfterCommentsHeading.includes('markdown-body');
            }

            const hasButton = html.includes('summarize-comments-btn');
            const hasQueuedState = html.includes('Your request is queued');

            // Summary is ready when we have the heading, content, and NO button/queued state
            const hasCommentSummary = hasCommentSummaryHeading &&
                                    hasMarkdownContent &&
                                    !hasButton &&
                                    !hasQueuedState;

            if (hasCommentSummary) {
                // Summary is ready!
                console.log('✓ Comment summary detected as ready, stopping polling and reloading...');
                this.stopPolling();
                this.hideLoading();
                this.showToast('Comment summary is ready! Refreshing page...', 'success');

                // Force reload with cache bypass
                setTimeout(() => {
                    console.log('Reloading page now...');
                    window.location.reload(true);
                }, 1000);
                return;
            }

            // Check if we've exceeded max attempts
            if (this.pollAttempts >= this.maxPollAttempts) {
                console.log('Polling timeout reached - summary still processing');
                this.stopPolling();
                // Keep the queued message showing - don't show button or toast
                return;
            }
        } catch (error) {
            console.error('Error checking summary status:', error);
            this.consecutiveErrors++;

            // Stop polling if we have too many consecutive errors
            if (this.consecutiveErrors >= this.maxConsecutiveErrors) {
                this.stopPolling();
                this.hideLoading();
                this.showError('Failed to check summary status. Please try again later.');
                return;
            }
            // Otherwise continue polling
        }
    }

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }

    // Simple markdown to HTML converter (basic implementation)
    markdownToHtml(markdown) {
        return markdown
            // Headers
            .replace(/^### (.*$)/gim, '<h3>$1</h3>')
            .replace(/^## (.*$)/gim, '<h2>$1</h2>')
            .replace(/^# (.*$)/gim, '<h1>$1</h1>')
            // Bold
            .replace(/\*\*(.*)\*\*/gim, '<strong>$1</strong>')
            // Italic
            .replace(/\*(.*)\*/gim, '<em>$1</em>')
            // Links
            .replace(/\[([^\]]+)\]\(([^\)]+)\)/gim, '<a href="$2" target="_blank" rel="noopener">$1</a>')
            // Line breaks
            .replace(/\n/gim, '<br>');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Static method to get singleton instance
    static getInstance() {
        if (!window.commentSummarizationManager) {
            window.commentSummarizationManager = new CommentSummarizationManager();
        }
        return window.commentSummarizationManager;
    }
}

// Auto-initialize
CommentSummarizationManager.getInstance();

// Export for manual use if needed
window.CommentSummarizationManager = CommentSummarizationManager; 