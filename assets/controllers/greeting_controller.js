import { Controller } from '@hotwired/stimulus';

/*
 * Cycles the homepage greeting through several languages.
 * Autoplay stops as soon as the visitor picks a language, and never starts
 * when they prefer reduced motion.
 */
export default class extends Controller {
    static targets = ['phrase', 'language', 'option'];
    static values = {
        greetings: Array,
        delay: { type: Number, default: 2600 },
    };

    connect() {
        this.index = 0;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.autoplay = !this.reducedMotion;
        this.resume();
    }

    disconnect() {
        this.pause();
    }

    pause() {
        clearInterval(this.timer);
        this.timer = null;
    }

    resume() {
        if (this.autoplay && !this.timer) {
            this.timer = setInterval(() => this.show(this.index + 1), this.delayValue);
        }
    }

    next() {
        this.stopAutoplay();
        this.show(this.index + 1);
    }

    select({ params: { index } }) {
        this.stopAutoplay();
        this.show(index);
    }

    stopAutoplay() {
        this.autoplay = false;
        this.pause();
    }

    show(index) {
        this.index = index % this.greetingsValue.length;
        const greeting = this.greetingsValue[this.index];

        this.phraseTarget.textContent = greeting.text;
        this.phraseTarget.lang = greeting.lang;
        this.languageTarget.textContent = greeting.name;
        this.optionTargets.forEach((option, i) => option.setAttribute('aria-pressed', String(i === this.index)));

        if (!this.reducedMotion) {
            this.phraseTarget.animate(
                [
                    { opacity: 0, transform: 'translateY(0.25em)', filter: 'blur(12px)' },
                    { opacity: 1, transform: 'none', filter: 'blur(0)' },
                ],
                { duration: 550, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' },
            );
        }
    }
}
