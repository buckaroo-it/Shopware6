/**
 * Shows the order-detail page loader while an order / payment / delivery state
 * transition request is running.
 *
 * The Buckaroo plugin performs work synchronously inside these state-transition
 * requests - capture-on-shipment (Klarna MoR, Klarna KP, Riverty) when a
 * delivery is shipped, and a Klarna MoR CancelReservation when an order is
 * cancelled - so the request can take a few seconds. The Shopware admin fires
 * the transition with no loading indicator (the confirmation modal closes
 * immediately), which makes the order page look frozen and invites a second
 * click. This decorator flips the swOrderDetail store's `order` loading flag
 * around every transition call; the order detail page already renders its
 * skeleton loader from that flag, and all three state cards
 * (sw-order-general-info, sw-order-details-state-card,
 * sw-order-state-history-card) go through this one service.
 */
const { Application } = Shopware;

const TRANSITION_METHODS = [
    'transitionOrderState',
    'transitionOrderTransactionState',
    'transitionOrderDeliveryState',
];

function getOrderDetailStore() {
    try {
        return Shopware.Store.get('swOrderDetail');
    } catch (e) {
        // Store not registered (transition triggered outside the order detail
        // page) - nothing to indicate, run the transition unchanged.
        return null;
    }
}

Application.addServiceProviderDecorator('orderStateMachineService', (orderStateMachineService) => {
    TRANSITION_METHODS.forEach((methodName) => {
        const original = orderStateMachineService[methodName];

        if (typeof original !== 'function') {
            return;
        }

        orderStateMachineService[methodName] = function decoratedTransition(...args) {
            const store = getOrderDetailStore();

            if (store) {
                store.setLoading(['order', true]);
            }

            let result;
            try {
                result = original.apply(this, args);
            } catch (error) {
                if (store) {
                    store.setLoading(['order', false]);
                }
                throw error;
            }

            if (store && result && typeof result.finally === 'function') {
                // `finally` passes the value/rejection through unchanged, so the
                // calling component's own then/catch handling keeps working.
                return result.finally(() => {
                    store.setLoading(['order', false]);
                });
            }

            if (store) {
                store.setLoading(['order', false]);
            }

            return result;
        };
    });

    return orderStateMachineService;
});
