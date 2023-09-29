import HttpClient from "src/service/http-client.service";
import Plugin from "src/plugin-system/plugin.class";

const BK_IS_MOBILE_EVENT_NAME = "bk-is-mobile";
const BK_SELECTED_ISSUER_EVENT_NAME = "bk-paybybank-selected";
export default class BuckarooPayByBankSelect extends Plugin {
  static options = {
    issuerSelected: "",
  };

  httpClient = new HttpClient();

  init() {
    this.listenToIsMobile();
    this.onPageLoad();
    this.listenToResize();
    this.listenToIssuerChange();
    this.togglePayByBankList();
    this.emitSavedIssuer();
  }

  emitSavedIssuer() {
    if (
      typeof this.options.issuerSelected == "string" &&
      this.options.issuerSelected.length > 0
    ) {
      document.$emitter.publish(BK_SELECTED_ISSUER_EVENT_NAME, {
        code: this.options.issuerSelected,
        source: "other",
      });
    }
  }

  onPageLoad() {
    document.$emitter.publish(BK_IS_MOBILE_EVENT_NAME, {
      isMobile:
        (window.innerWidth ||
          document.documentElement.clientWidth ||
          document.body.clientWidth) < 768,
    });
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
        this.toggleInputType();
      }.bind(this)
    );
  }

  toggleInputType() {
    const select = document.querySelector(".bk-paybybank-mobile");
    const radioGroup = document.querySelector(".bk-paybybank-not-mobile");
    if (this.isMobile && select && radioGroup) {
      select.style.display = "block";
      radioGroup.style.display = "none";
    } else {
      select.style.display = "none";
      radioGroup.style.display = "block";
    }
  }


  togglePayByBankList() {

    this._elementsToShow = document.querySelectorAll(
      ".bk-paybybank-selector .custom-radio:nth-child(n+6)"
    );

    setTimeout(() => {
      const val = localStorage.getItem("confirmOrderForm.payBybankMethod");
      if (val !== null) {
        document.$emitter.publish(BK_SELECTED_ISSUER_EVENT_NAME, {
          code: val,
          source: "other",
        });
      }
    }, 300);
    this.toggleElements(false);

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
        this.toggleElements(isDown);
      }
    }.bind(this));
  }

  listenToIssuerChange() {

    const setToggleToMore = function () {
      const payByBankList = document.querySelector(".bk-toggle-wrap");
      if (payByBankList !== null) {
        const textElement = payByBankList.querySelector(".bk-toggle-text");
        const toggle = payByBankList.querySelector(".bk-toggle");
        const isDown = toggle.classList.contains("bk-toggle-down");
        const textMore = textElement.getAttribute("text-more");
        if (!isDown) {
          toggle.classList.toggle("bk-toggle-down");
          toggle.classList.toggle("bk-toggle-up");
          textElement.textContent = textMore;
        }
      }
    }

    document.$emitter.subscribe(
      BK_SELECTED_ISSUER_EVENT_NAME,
      function (event) {
        this.syncInputs(event.detail);
      }.bind(this)
    );

    const select = document.querySelector("#payBybankMethod");

    select.addEventListener("change", function (event) {
      document.$emitter.publish(BK_SELECTED_ISSUER_EVENT_NAME, {
        code: event.target.value,
        source: "select",
      });
      setToggleToMore();

    });

    const radiosInGroup = document.querySelectorAll(
      ".bk-paybybank-radio input"
    );

    radiosInGroup.forEach(function (radio) {
      radio.addEventListener("change", function (event) {
        document.$emitter.publish(BK_SELECTED_ISSUER_EVENT_NAME, {
          code: event.target.value,
          source: "radio",
        });
      });
    });

  }

  syncInputs(data) {
    this._elementsToShow = document.querySelectorAll(
      `.bk-paybybank-selector .custom-radio:not(.bankMethod${data.code})`
    );

    if (data.source === 'other') {
      this.toggleElements(false);
    }

    if (["radio", "other"].indexOf(data.source) !== -1) {
      const select = document.querySelector("#payBybankMethod");
      select.value = data.code;
    }

    if (["select", "other"].indexOf(data.source) !== -1) {
      const radiosInGroup = document.querySelectorAll(".bk-paybybank-radio");
      radiosInGroup.forEach(function (radioWrap) {
        const radio = radioWrap.querySelector("input");
        radioWrap.style.display = "none";
        if (radio !== null) {
          radio.checked = false;
          if (radio.value === data.code) {
            radio.checked = true;
            radioWrap.style.display = "block";
          }
        }
      });
    }
  }
  toggleElements(show, defaultDisplay = "inline") {
    this._elementsToShow.forEach(function (element) {
      element.style.display = show ? defaultDisplay : "none";
    });
  }

}
