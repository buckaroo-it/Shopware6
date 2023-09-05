import Plugin from "src/plugin-system/plugin.class";

const BK_SELECTED_ISSUER_EVENT_NAME = "bk-paybybank-selected";
export default class BuckarooPayByBankLogo extends Plugin {
  static options = {
    issuerSelected: "",
    issuerLogos: [],
  };

  init() {
    this.initialLogo();
    this.listenToIssuerChange();
  }

  listenToIssuerChange() {
    document.$emitter.subscribe(
      BK_SELECTED_ISSUER_EVENT_NAME,
      function (event) {
        this.updateLogo(event.detail.code);
      }.bind(this)
    );
  }

  initialLogo() {
    this.updateLogo(this.options.issuerSelected);
  }

  updateLogo(code) {
    if (this.options.issuerLogos[code]) {
      let img = document.querySelector(".bk-paybybank .payment-method-image");
      if (img) img.src = this.options.issuerLogos[code];
    }
  }
}
