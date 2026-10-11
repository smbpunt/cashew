import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/*
 * Leaves the page once its animation is over, for the URL it's given: through
 * Turbo, which crossfades to it. Right away for those who prefer less motion.
 */
export default class extends Controller {
    static values = { url: String, delay: { type: Number, default: 1800 } };

    connect() {
        const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.timeout = setTimeout(() => visit(this.urlValue), reduced ? 0 : this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
