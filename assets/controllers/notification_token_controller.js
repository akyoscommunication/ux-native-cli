import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

function callLiveAction(controller, action, args) {
    const host = controller.element.closest('[data-controller~="live"]')
        || controller.element.querySelector('[data-controller~="live"]');
    const live = host && controller.application.getControllerForElementAndIdentifier(host, 'live');
    if (!live?.component) {
        return;
    }
    live.component.action(action, args);
}

function bridgeField(message, key) {
    let data = message?.data ?? message;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch {
            return null;
        }
    }
    const value = data?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}

/*
 * Web side of the "notification-token" bridge. Inside a Live Component that uses
 * Akyos\UxNativeCliBundle\Live\NotificationTrait, #get calls receiveNotificationToken.
 *
 *   <div data-controller="bridge--notification-token">
 *       <button type="button" data-action="bridge--notification-token#get">Activer</button>
 *       <p data-bridge--notification-token-target="token"></p>
 *   </div>
 *
 * Event: "bridge--notification-token:retrieved" (detail.token).
 */
export default class extends BridgeComponent {
    static component = 'notification-token';
    static targets = ['token'];

    get() {
        this.send('get', {}, (message) => {
            const token = bridgeField(message, 'token');
            const error = bridgeField(message, 'error');
            if (this.hasTokenTarget && token) {
                this.tokenTarget.textContent = token;
            }
            this.dispatch('retrieved', { detail: { token, error } });
            if (token) {
                callLiveAction(this, 'receiveNotificationToken', { token });
            } else if (error) {
                callLiveAction(this, 'failNotificationToken', { error });
            }
        });
    }
}
