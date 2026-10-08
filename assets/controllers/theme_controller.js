import { Controller } from '@hotwired/stimulus';

/*
 * Toggles the shadcn dark theme (the `.dark` class on <html>) and remembers
 * the choice. The initial theme is applied by an inline script in base.html.twig
 * to avoid a flash of the wrong theme.
 */
export default class extends Controller {
    toggle() {
        const dark = document.documentElement.classList.toggle('dark');

        try {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
        } catch {
            // Storage can be unavailable (private mode); the toggle still works for this page.
        }
    }
}
