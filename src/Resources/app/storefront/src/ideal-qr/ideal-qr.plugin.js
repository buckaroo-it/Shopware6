import Plugin from "src/plugin-system/plugin.class";
import HttpClient from "src/service/http-client.service";

export default class IdealQrPlugin extends Plugin {
  static options = {
    orderId: null,
    pullUrl: null,
    interval: 5000,
  };

  httpClient = new HttpClient();

  init() {
    this.pullStatus();
  }

  pullStatus() {
    setInterval(
        this.singlePullStatus.bind(this),
        this.options.interval
    );
  }

  singlePullStatus() {
    this.httpClient.post(
      this.options.pullUrl,
      JSON.stringify({
        orderId: this.options.orderId,
      }),
      (response) => {
        const res = JSON.parse(response);
        if (res.redirectUrl !== undefined) {
          window.location.href = res.redirectUrl;
        }
      }
    );
  }
}
