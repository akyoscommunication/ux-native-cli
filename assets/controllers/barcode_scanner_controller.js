import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

function callLiveAction(controller, action, args) {
    const host = controller.element.closest('[data-controller~="live"]');
    const live = host && controller.application.getControllerForElementAndIdentifier(host, 'live');
    live?.component?.action(action, args);
}

function liveProp(controller, name) {
    const host = controller.element.closest('[data-controller~="live"]');
    const raw = host?.getAttribute('data-live-props-value');
    if (!raw) {
        return null;
    }
    try {
        const value = JSON.parse(raw)[name];
        return typeof value === 'string' && value !== '' ? value : null;
    } catch {
        return null;
    }
}

/*
 * Web side of the "barcode-scanner" bridge. Inside a Live Component that uses
 * Akyos\UxNativeCliBundle\Live\ScanQRTrait, a successful scan calls receiveScannedCode.
 */
export default class extends BridgeComponent {
    static component = 'barcode-scanner';
    static values = {
        auto: Boolean,
    };

    connect() {
        super.connect();
        if (this.autoValue && !liveProp(this, 'scannedCode')) {
            this.scan();
        }
    }

    scan() {
        this.send('scan', {}, (message) => {
            const code = message.data?.code ?? null;
            this.dispatch(code ? 'scanned' : 'cancelled', { detail: { code } });
            if (code) {
                callLiveAction(this, 'receiveScannedCode', { code });
            }
        });
    }
}
