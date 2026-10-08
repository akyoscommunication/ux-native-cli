import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

/*
 * Web side of the "barcode-scanner" bridge component of the Hotwire Native shells (doc/17-native.md,
 * native/bridge/barcode-scanner/): asks the shell to scan a QR code with the camera and dispatches the result.
 * Stimulus only loads it inside a shell that registers the component: in a browser, nothing happens.
 *
 *   <div data-controller="bridge--barcode-scanner"
 *        data-bridge--barcode-scanner-auto-value="true"
 *        data-action="bridge--barcode-scanner:scanned->my-controller#useCode">
 *       <button type="button" data-action="bridge--barcode-scanner#scan">Scanner</button>
 *   </div>
 *
 * Events: "bridge--barcode-scanner:scanned" (detail.code) and "bridge--barcode-scanner:cancelled".
 */
export default class extends BridgeComponent {
    static component = 'barcode-scanner';
    static values = {
        // Opens the scanner as soon as the element shows up.
        auto: Boolean,
    };

    connect() {
        super.connect();
        if (this.autoValue) {
            this.scan();
        }
    }

    scan() {
        this.send('scan', {}, (message) => {
            const code = message.data.code;
            this.dispatch(code ? 'scanned' : 'cancelled', { detail: { code } });
        });
    }
}
