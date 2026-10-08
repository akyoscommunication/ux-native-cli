import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

/*
 * Web side of the "admob" bridge component of the Hotwire Native shells (doc/17-native.md,
 * native/bridge/admob/): asks the shell to show a Google AdMob ad. Stimulus only loads it inside a shell
 * that registers the component: in a browser, nothing happens (the other actions of the element still run).
 *
 * Ad units differ per platform (values "androidUnit" / "iosUnit"). Actions, one per ad format:
 *  - rewarded      full screen -> "bridge--admob:earned" (detail.reward) or "bridge--admob:dismissed"
 *  - interstitial  full screen -> "bridge--admob:dismissed"
 *  - banner        anchored at the "position" value ("top" | "bottom") -> "bridge--admob:loaded" (detail.height)
 *  - native        small native ad card, anchored the same way -> "bridge--admob:loaded"
 *  - hide          removes the anchored ad
 * Every format dispatches "bridge--admob:failed" (detail.error) when no ad can be shown. Events are dispatched on
 * the element that triggered the action. A full screen action stops the other actions of the same event: put
 * what must happen after the ad on "bridge--admob:earned" / ":dismissed".
 *
 *   <div data-controller="bridge--admob" data-bridge--admob-android-unit-value="…" data-bridge--admob-ios-unit-value="…">
 *       <button data-action="bridge--admob#rewarded bridge--admob:earned->my-controller#reward">Watch an ad</button>
 *       <p hidden data-bridge--admob-target="error">No ad right now.</p>
 *   </div>
 */
export default class extends BridgeComponent {
    static component = 'admob';
    static targets = ['error'];
    static values = {
        androidUnit: String,
        iosUnit: String,
        position: { type: String, default: 'bottom' },
    };

    disconnect() {
        if (this.anchored) {
            this.hide();
        }
        super.disconnect();
    }

    rewarded(event) {
        this.#fullScreen('rewarded', event);
    }

    interstitial(event) {
        this.#fullScreen('interstitial', event);
    }

    banner(event) {
        this.#anchor('banner', event);
    }

    native(event) {
        this.#anchor('native', event);
    }

    hide() {
        this.anchored = false;
        this.send('hide');
        document.documentElement.style.removeProperty('--admob-anchored-height');
    }

    #fullScreen(format, event) {
        const trigger = event?.currentTarget ?? this.element;
        // The ad comes first: what the element does next waits for its result.
        event?.preventDefault();
        event?.stopImmediatePropagation();
        if (this.busy) {
            return;
        }

        this.#setBusy(trigger, true);
        this.send(format, { adUnitId: this.#adUnitId }, ({ data }) => {
            this.#setBusy(trigger, false);
            this.#report(trigger, data);
        });
    }

    #anchor(format, event) {
        const trigger = event?.currentTarget ?? this.element;
        this.anchored = true;
        this.send(format, { adUnitId: this.#adUnitId, position: this.positionValue }, ({ data }) => {
            if ('loaded' === data.status) {
                // Lets the page keep its content clear of the ad.
                document.documentElement.style.setProperty('--admob-anchored-height', `${data.height}px`);
            } else {
                this.anchored = false;
            }
            this.#report(trigger, data);
        });
    }

    #report(trigger, { status, reward, height, error }) {
        this.errorTargets.forEach((element) => { element.hidden = 'failed' !== status; });
        this.dispatch(status, { target: trigger, detail: { reward, height, error } });
    }

    #setBusy(trigger, busy) {
        this.busy = busy;
        trigger.toggleAttribute('aria-busy', busy);
        if ('disabled' in trigger) {
            trigger.disabled = busy;
        }
    }

    get #adUnitId() {
        return 'ios' === document.documentElement.dataset.bridgePlatform ? this.iosUnitValue : this.androidUnitValue;
    }
}
