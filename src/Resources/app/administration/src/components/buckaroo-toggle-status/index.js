const { Component, Mixin } = Shopware;
import template from "./buckaroo-toggle-status.html.twig";
import './style.scss'

Component.register("buckaroo-toggle-status", {
    template,
    props: {
        method: {
            type: String,
            required: true
        },
        value: {
            required: true
        },
        currentSalesChannelId: {
            required: true,
        }
    },
    mixins: [
        Mixin.getByName('notification'),
    ],
    inject: ['systemConfigApiService'],
    data() {
        return {
            status: this.getStatus(),
            isLoading: false,
        }
    },
    methods: {
        getStatus() {
            if (!this.isActive()) {
                return 'disabled'
            }
            return this.getEnvironment()
        },
        isActive() {
            return this.getValueForName(`${this.method}Enabled`);
        },
        getEnvironment() {
            const env = this.getValueForName(`${this.method}Environment`);
            if (env === undefined) {
                return 'test';
            }
            return env;
        },
        getValueForName(name) {
            return this.value?.[`BuckarooPayments.config.${name}`];
        },
        setStatus(status) {
            this.status = status;
            this.saveStatus();
        },
        getClass(buttonStatus) {
            return this.status === buttonStatus ? 'active' : '';
        },
        async saveStatus() {
            const enabledKey = `BuckarooPayments.config.${this.method}Enabled`;
            const environmentKey = `BuckarooPayments.config.${this.method}Environment`;

            let data = {[enabledKey]: false};
            this.$set(this.value, enabledKey, false )

            if (['live', 'test'].indexOf(this.status) !== -1) {
                data = {
                    [enabledKey]: true,
                    [environmentKey]: this.status
                }
            this.$set(this.value, enabledKey, true )
            this.$set(this.value, environmentKey, this.status )

            }

            this.isLoading = true;
            try {
                await this.systemConfigApiService
                .batchSave({[this.currentSalesChannelId]: data})
                .finally(() => {
                    this.isLoading = false;
                });
                this.renderSuccess();
            } catch (error) {
                this.renderError(error);
            }
           
        },
        renderSuccess() {
            this.createNotificationSuccess({
                message: this.$tc('sw-extension-store.component.sw-extension-config.messageSaveSuccess'),
            });
        },

        renderError(err) {
            this.createNotificationError({
                message: err ,
            });
        }
    }
})