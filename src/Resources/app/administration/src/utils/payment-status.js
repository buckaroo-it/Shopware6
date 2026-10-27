/**
 * Reads payment method settings from the system config value object and
 * resolves the status the storefront will actually use.
 *
 * These rules repeat SettingsService (getEnabled, isTestEnvironment and
 * SHARED_ENVIRONMENT_METHODS). Keep both in sync: if they differ, the admin shows
 * another mode than the shop uses. AdminPaymentStatusSyncTest checks the shared list.
 */

// Methods without their own environment fall back to another method's (mirrors SettingsService::SHARED_ENVIRONMENT_METHODS)
export const SHARED_ENVIRONMENT_METHODS = {
    idealqr: 'ideal',
    klarnain: 'klarna',
};

const CONFIG_PREFIX = 'BuckarooPayments.config.';

/**
 * Returns a copy of the settings value with the given fields set. The settings form keeps
 * both full ("BuckarooPayments.config.x") and short ("x") keys, so both are updated.
 * Field names may be given in either form.
 */
export function withConfigValues(value, fields) {
    const updated = { ...value };

    Object.entries(fields).forEach(([name, fieldValue]) => {
        const shortName = name.replace(CONFIG_PREFIX, '');
        updated[shortName] = fieldValue;
        updated[`${CONFIG_PREFIX}${shortName}`] = fieldValue;
    });

    return updated;
}

export function getConfigValue(value, name) {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const key = `BuckarooPayments.config.${name}`;
    let val;

    if (value[key] !== undefined) {
        val = value[key];
    } else if (value[name] !== undefined) {
        val = value[name];
    } else if (value['BuckarooPayments.config'] && typeof value['BuckarooPayments.config'] === 'object') {
        val = value['BuckarooPayments.config'][name];
    } else {
        const variations = [
            name.toLowerCase(),
            name.charAt(0).toLowerCase() + name.slice(1),
            name.charAt(0).toUpperCase() + name.slice(1),
        ];

        for (const variation of variations) {
            if (value[`BuckarooPayments.config.${variation}`] !== undefined) {
                val = value[`BuckarooPayments.config.${variation}`];
                break;
            }
            if (value[variation] !== undefined) {
                val = value[variation];
                break;
            }
        }
    }

    if (val && typeof val === 'object' && Object.prototype.hasOwnProperty.call(val, '_value')) {
        val = val._value;
    }

    return val;
}

export function isPaymentEnabled(value, method) {
    const enabled = getConfigValue(value, `${method}Enabled`);

    if (typeof enabled === 'string') {
        return enabled.toLowerCase() === 'true' || enabled === '1';
    }

    return Boolean(enabled);
}

function getNormalizedEnvironment(value, method) {
    const environment = getConfigValue(value, `${method}Environment`);

    return typeof environment === 'string' ? environment.trim().toLowerCase() : '';
}

/**
 * Same rules as SettingsService::isTestEnvironment(): only an explicit "test" is test mode.
 *
 * @returns {'live'|'test'}
 */
export function getPaymentMode(value, method) {
    let configMethod = method;

    if (SHARED_ENVIRONMENT_METHODS[method]) {
        if (getNormalizedEnvironment(value, method) === 'live') {
            return 'live';
        }
        configMethod = SHARED_ENVIRONMENT_METHODS[method];
    }

    return getNormalizedEnvironment(value, configMethod) === 'test' ? 'test' : 'live';
}
