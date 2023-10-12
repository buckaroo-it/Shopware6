const { Component, Mixin } = Shopware;

import template from './buckaroo-settings.html.twig';
import './style.scss';

Component.register('buckaroo-settings', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    inject: [ 'BuckarooPaymentSettingsService' ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            config: {},
            phpversion: false,
            supportMessage: false,
            isSupportModalOpen: false,
            isSupportMessageModalOpen: false,
            isPhpVersionSupport: false,
        };
    },

    created() {
        let that = this;
        this.BuckarooPaymentSettingsService.getSupportVersion()
        .then((result) => {
            that.phpversion = result.phpversion;
            that.isPhpVersionSupport = result.isPhpVersionSupport;
        });
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onConfigChange(config) {
            this.config = config;
        },

        onSave() {
            this.isSaveSuccessful = false;
            this.isLoading = true;
          
            this.$refs.systemConfig.saveAll().then(() => {
                this.isLoading = false;
                this.isSaveSuccessful = true;
            }).catch(() => {
                this.isLoading = false;
            });
        }
    }
});
