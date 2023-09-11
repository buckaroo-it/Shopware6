const { Component } = Shopware;

import template from "./buckaroo-in3-logo.html.twig";
import "./style.scss";

Component.register("buckaroo-in3-logo", {
  template,

  inject: ["BuckarooPaymentSettingsService"],

  data() {
    return {
      logos: [],
      selectedLogo: null,
    };
  },

  model: {
    prop: "value",
    event: "change",
  },

  props: {
    name: {
      type: String,
      required: true,
      default: "",
    },
    value: {
      type: String,
      required: false,
      default() {
        return null;
      },
    },
  },

  watch: {
    selectedLogo(value) {
      this.$emit("change", value);
    },
  },

  created() {
    this.BuckarooPaymentSettingsService.getIn3Icons().then((result) => {
      this.setInitialValue();
      this.logos = result.logos;
    });
  },
  methods: {
    setInitialValue() {
      if (!this.value) {
        this.selectedLogo = "default_payment_icon";
        this.$emit("change", this.selectedLogo);
      } else {
        this.selectedLogo = this.value;
      }
    },
  },
});
