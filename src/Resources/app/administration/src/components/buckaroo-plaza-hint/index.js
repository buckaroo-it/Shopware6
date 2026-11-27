import template from './buckaroo-plaza-hint.html.twig';
import './style.scss';

const { Component } = Shopware;

// {link} or {link:Link text}
const LINK_PLACEHOLDER = /\{link(?::([^}]*))?\}/;

/**
 * A line of text below a setting with a link to Buckaroo Plaza, which opens in a new tab.
 * Used in config.xml as <component name="buckaroo-plaza-hint"> with a <url> and a <label> that holds
 * the text, with {link:Link text} where the link goes; {link} uses <linkText> instead. <field> names the
 * setting it belongs to: the hint is shown only together with that setting. Unlike a help text,
 * Shopware does not sanitize this link, so it keeps target="_blank".
 */
Component.register('buckaroo-plaza-hint', {
    template,

    props: {
        text: {
            type: String,
            required: false,
            default: '',
        },
        url: {
            type: String,
            required: true,
        },
        linkText: {
            type: String,
            required: false,
            default: '',
        },
    },

    computed: {
        parts() {
            const match = this.text.match(LINK_PLACEHOLDER);

            if (!match) {
                return { before: this.text, link: this.linkText, after: '' };
            }

            return {
                before: this.text.slice(0, match.index),
                link: match[1] || this.linkText,
                after: this.text.slice(match.index + match[0].length),
            };
        },
    },
});
