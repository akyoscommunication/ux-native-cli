import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

/*
 * Bridge "game": on a game page, the app goes landscape and hides its bars (tabs, system bars), then puts
 * everything back when the page is left. Without the native app (or with a version that does not know the
 * bridge), nothing happens.
 *
 *   <div data-controller="bridge--game">…</div>
 */
export default class extends BridgeComponent {
    static component = 'game';

    connect() {
        super.connect();
        this.send('enter');
    }

    disconnect() {
        this.send('leave');
        super.disconnect();
    }
}
