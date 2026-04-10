(function () {
    function register() {
        const registry = window.wc?.blocksRegistry || window.wc?.wcBlocksRegistry;
        if (!registry?.registerPaymentMethod) return false;

        const wp = window.wp || {};
        const __ = wp.i18n?.__ || ((s) => s);
        const el = wp.element?.createElement;

        if (!el) return false;

        const iconUrl =
            window.enkapPayIconUrl ||
            window.location.origin +
            '/wp-content/plugins/e-nkap-woocommerce-gateway/includes/assets/images/e-nkap.png';

        const label = el(
            'span',
            {
                style: {
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '8px',
                },
            },
            el('img', {
                src: iconUrl,
                alt: __('SmobilPay for e-commerce', 'wc-wp-enkap'),
                style: { height: '20px' },
            }),
            el('span', null, __('SmobilPay for e-commerce', 'wc-wp-enkap'))
        );

        const Content = ({ eventRegistration, emitResponse }) => {
            wp.element.useEffect(() => {
                if (!eventRegistration?.onPaymentSetup) return;

                return eventRegistration.onPaymentSetup(() => {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {}
                        },
                    };
                });
            }, []);

            return el(
                'p',
                null,
                __('You will be redirected to complete your payment.', 'wc-wp-enkap')
            );
        };

        registry.registerPaymentMethod({
            name: 'e_nkap',
            label,
            ariaLabel: __('SmobilPay for e-commerce', 'wc-wp-enkap'),
            content: el(Content),
            edit: el(Content),
            canMakePayment: () => true,

            supports: {
                features: ['products'],
            },
        });

        return true;
    }

    if (!register()) {
        document.addEventListener('DOMContentLoaded', register);
    }
})();