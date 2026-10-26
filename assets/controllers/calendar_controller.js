import { BridgeComponent } from '@hotwired/hotwire-native-bridge';

/*
 * Bridge "calendar": opens the phone's calendar on a new event. Dates are Unix timestamps in milliseconds.
 * Without the native app (or with a version that does not know the bridge), nothing happens and the
 * default behavior of the link or button stays prevented: keep a fallback (an .ics link) outside the app.
 *
 *   <div data-controller="bridge--calendar"
 *        data-bridge--calendar-title-value="Match"
 *        data-bridge--calendar-starts-value="1767268800000"
 *        data-bridge--calendar-ends-value="1767276000000"
 *        data-bridge--calendar-location-value="Stade de France">
 *       <button data-action="bridge--calendar#add">Ajouter à mon agenda</button>
 *   </div>
 */
export default class extends BridgeComponent {
    static component = 'calendar';

    static values = {
        title: String,
        starts: Number,
        ends: Number,
        location: String,
    };

    add(event) {
        event.preventDefault();
        this.send('add', {
            title: this.titleValue,
            startsAt: this.startsValue,
            endsAt: this.endsValue,
            location: this.locationValue,
        });
    }
}
