import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class MatomoResolutionPlugin extends Plugin {
    init() {
        this._client = new HttpClient();
        this.sendResolution();
    }

    sendResolution() {
        const width = screen.width;
        const height = screen.height;
        const resolution = width + "x" + height;

        // Speichern in Session-Cookie
        document.cookie = "matomo_screen_resolution=" + resolution + ";path=/";

        // AJAX-Request zum sofortigen Aktualisieren der Server-Session
        this._client.post('/matomo-save-resolution', JSON.stringify({
            width: width,
            height: height
        }), null, 'application/json');
    }

}