import HttpClient from "src/service/http-client.service";
import Plugin from "src/plugin-system/plugin.class";

const BK_IS_MOBILE_EVENT_NAME = "bk-is-mobile";
const BK_SELECTED_ISSUER_EVENT_NAME = "bk-paybybank-selected";
export default class BuckarooPayByBankSelect extends Plugin {
  static options = {};

  httpClient = new HttpClient();

  init() {
    this.listenToIsMobile();
    this.onPageLoad();
    this.listenToResize();
    this.togglePayByBankList();
    this.listenToIssuerChange();
  }

  onPageLoad() {
    setTimeout(function () {
      document.$emitter.publish(BK_IS_MOBILE_EVENT_NAME, {
        isMobile:
          (window.innerWidth ||
            document.documentElement.clientWidth ||
            document.body.clientWidth) < 768,
      });
    }, 100);
  }
  listenToResize() {
    window.addEventListener(
      "resize",
      function () {
        let w =
          window.innerWidth ||
          document.documentElement.clientWidth ||
          document.body.clientWidth;

        let isMobile = false;
        if (w < 768) {
          isMobile = true;
        }

        if (this.isMobile !== isMobile) {
          document.$emitter.publish(BK_IS_MOBILE_EVENT_NAME, { isMobile });
        }
      }.bind(this)
    );
  }

  listenToIsMobile() {
    document.$emitter.subscribe(
      BK_IS_MOBILE_EVENT_NAME,
      function (event) {
        this.isMobile = event.detail.isMobile;
        this.getFormElement();
      }.bind(this)
    );
  }

  getFormElement() {
    this.httpClient.post(
      `/buckaroo/pybybank`,
      JSON.stringify({
        isMobile: this.isMobile,
      }),
      this.renderFormElement.bind(this)
    );
  }
  renderFormElement(data) {
    if (typeof data === "string") {
      this.el.innerHTML = data;
    }

    if (this.isMobile === false) {
      this.toggleElements(false);

      this.setInitialLogo();
    }
  }

  toggleElements(show, defaultDisplay = "inline") {
    let elementsToShow = document.querySelectorAll(
      ".bk-paybybank-selector .custom-radio:nth-child(n+6)"
    );
    const selectedRadio = this.issuerSelectedRadio();
    if (selectedRadio !== undefined) {
      elementsToShow = document.querySelectorAll(
        ".bk-paybybank-selector .custom-radio:not(." + selectedRadio + ")"
      );
    }

    elementsToShow.forEach(function (element) {
      element.style.display = show ? defaultDisplay : "none";
    });
  }

  issuerSelectedRadio() {
    let issuerRadio;
    document.querySelectorAll(".bank-method-input").forEach((element) => {
      if (element.checked === true) {
        issuerRadio = element.id;
      }
    });

    return issuerRadio;
  }

  togglePayByBankList() {
    localStorage.removeItem("confirmOrderForm.payBybankMethodId");
    const self = this;
    this.el.addEventListener("click", function (event) {
      const payByBankList = document.querySelector(".bk-toggle-wrap");
      if (payByBankList === null) {
        return;
      }
      const textElement = payByBankList.querySelector(".bk-toggle-text");
      if (event.target === textElement) {
        const toggle = payByBankList.querySelector(".bk-toggle");
        const isDown = toggle.classList.contains("bk-toggle-down");
        toggle.classList.toggle("bk-toggle-down");
        toggle.classList.toggle("bk-toggle-up");
        const textLess = textElement.getAttribute("text-less");
        const textMore = textElement.getAttribute("text-more");
        if (isDown) {
          textElement.textContent = textLess;
        } else {
          textElement.textContent = textMore;
        }
        self.toggleElements(isDown);
      }
    });
  }

  listenToIssuerChange() {
    const wrapper = document.querySelector(".paybybank-main-wrapper");
    wrapper.addEventListener("change", function (event) {
      if (event.target.name === "payBybankMethodId") {
        if (event.target.type === "select-one") {
          document.$emitter.publish(BK_SELECTED_ISSUER_EVENT_NAME, {
            code: event.target.value,
          });
        } else {
          const selected = document.querySelector(
            'input[name="payBybankMethodId"]:checked'
          );
          if (selected !== undefined) {
            document.$emitter.publish(BK_SELECTED_ISSUER_EVENT_NAME, {
              code: selected.value,
            });
          }
        }
      }
    });

    document.$emitter.subscribe(
      BK_SELECTED_ISSUER_EVENT_NAME,
      function (event) {
        this.setPaymentLogo(event.detail.code);
      }.bind(this)
    );
  }


  setInitialLogo() {
    document.querySelectorAll(".bank-method-input").forEach((element) => {
        if (element.checked === true) {
            this.setPaymentLogo(element.value);
        }
      });
  }
  setPaymentLogo(issuer) {
    this.httpClient.post(
      `/buckaroo/pybybank/logo`,
      JSON.stringify({
        issuer: issuer,
      }),
      this.changeLogo.bind(this)
    );
  }

  changeLogo(response) {
    const res = JSON.parse(response);
    if (res.error !== true && typeof res.logo === 'string') {
        let img = document.querySelector(".bk-paybybank .payment-method-image");
        if (img) img.src = res.logo;
    }
  }
}
