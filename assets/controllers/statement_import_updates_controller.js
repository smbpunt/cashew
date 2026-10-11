import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

/*
 * Refreshes the enclosing live component as soon as the Mercure hub announces
 * a change in one of the imports it follows. The component polls as well,
 * which covers a missed update or an unreachable hub.
 */
export default class extends Controller {
    static values = { url: String };

    async connect() {
        this.component = await getComponent(this.element.closest('[data-controller~="live"]'));
        this.eventSource = new EventSource(this.urlValue, { withCredentials: true });
        this.eventSource.onmessage = () => this.component.action('refresh');
        // An import may be over before the connection opens: catch up once it does
        this.eventSource.onopen = () => this.component.action('refresh');
    }

    disconnect() {
        this.eventSource?.close();
    }
}
