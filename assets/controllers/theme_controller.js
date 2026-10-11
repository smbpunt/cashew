import { Controller } from '@hotwired/stimulus';

const THEMES = ['light', 'soft', 'dark'];

/*
 * Picks one of the themes, each a class on <html> (none for light, `.soft`, `.dark`), and remembers the
 * choice. The initial theme is applied by an inline script in base.html.twig to avoid a flash of the
 * wrong theme; this controller only marks the matching option as pressed.
 */
export default class extends Controller {
    static targets = ['option'];

    connect() {
        this.#press(THEMES.find((theme) => document.documentElement.classList.contains(theme)) ?? 'light');
    }

    choose({ params: { theme } }) {
        for (const other of THEMES) {
            document.documentElement.classList.toggle(other, other === theme && other !== 'light');
        }
        this.#press(theme);

        try {
            localStorage.setItem('theme', theme);
        } catch {
            // Storage can be unavailable (private mode); the choice still applies to this page.
        }
    }

    #press(theme) {
        for (const option of this.optionTargets) {
            option.setAttribute('aria-pressed', String(option.dataset.themeThemeParam === theme));
        }
    }
}
