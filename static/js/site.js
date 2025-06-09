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