import { post } from "../helper/buckaroo-http";
const Plugin = window.PluginBaseClass;

export default class IdealQrPlugin extends Plugin {
  static options = {
    orderId: null,
    pullUrl: null,
    interval: 5000,
  };

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
    post(
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
