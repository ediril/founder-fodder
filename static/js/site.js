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
            const response = await fetch(`/create-comment-request/${storyId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            const result = await response.json();

            if (response.status === 429) {
                // Server-side rate limiting
                const remainingTime = result.remaining_seconds || 15;
                this.showToast(`Please wait ${remainingTime} seconds before requesting again.`, 'warning');
                this.showButton(); // Show button again
                return;
            }

            if (result.success) {
                // Start polling to check when summary is ready
                this.startPolling(storyId);
            } else {
                this.showError(result.error || 'Failed to start summary generation');
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
        this.maxPollAttempts = 5;

        // Start the polling
        this.pollInterval = setInterval(() => {
            this.checkSummaryStatus(storyId);
        }, 3000);
    }

    async checkSummaryStatus(storyId) {
        this.pollAttempts++;

        try {
            // Check if summary is ready by looking for the button vs summary content
            const response = await fetch(`/summary/${storyId}`);
            const html = await response.text();

            // If the page contains a comment summary (has markdown-body in comments section)
            // and no longer shows the "Summarize Comments" button, then it's ready
            const hasCommentSummary = html.includes('Comments Summary') &&
                                    html.includes('markdown-body') &&
                                    !html.includes('summarize-comments-btn');

            if (hasCommentSummary) {
                // Summary is ready!
                this.stopPolling();
                this.showToast('Comment summary is ready! Refreshing page...', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
                return;
            }

            // Check if we've exceeded max attempts
            if (this.pollAttempts >= this.maxPollAttempts) {
                this.stopPolling();
                this.showToast('Summary is taking longer than expected. Please refresh the page manually.', 'warning');
                this.showButton(); // Show button again
                return;
            }
        } catch (error) {
            console.error('Error checking summary status:', error);
            // Continue polling on error, don't stop
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