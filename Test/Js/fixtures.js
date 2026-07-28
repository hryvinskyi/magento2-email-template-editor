/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Template content the scanner is measured against.
 *
 * Everything here except the last group is copied verbatim out of a template Magento ships, with
 * the file it came from named above it. Hand-written samples would only ever exercise the grammar
 * that was in mind while writing them, and the awkward shapes - a directive split over four lines,
 * a chain that starts after a blank line, a quoted parameter holding markup - are exactly the ones
 * a tidy sample never has.
 *
 * The last group is deliberately synthetic and says so: no template Magento ships carries an
 * expression anywhere near the length limit, so the only way to exercise the limit is to build a
 * string that reaches it.
 */

/* vendor/magento/module-sales/view/frontend/email/order_new.html */
var ORDER_NEW_INTRO = `            <p class="greeting">{{trans "%customer_name," customer_name=$order_data.customer_name}}</p>
            <p>
                {{trans "Thank you for your order from %store_name." store_name=$store.frontend_name}}
                {{trans "Once your package ships we will send you a tracking number."}}
            </p>`;

/* vendor/magento/module-sales/view/frontend/email/order_new.html */
var ORDER_NEW_CUSTOMER_NOTE = `            {{depend order_data.email_customer_note}}
            <table class="message-info">
                <tr>
                    <td>
                        {{var order_data.email_customer_note|escape|nl2br}}
                    </td>
                </tr>
            </table>
            {{/depend}}`;

/* vendor/magento/module-sales/view/frontend/email/order_new.html */
var ORDER_NEW_SHIPPING_METHOD = `                    <td class="method-info">
                        <h3>{{trans "Shipping Method"}}</h3>
                        <p>{{var order.shipping_description}}</p>
                        {{if shipping_msg}}
                        <p>{{var shipping_msg}}</p>
                        {{/if}}
                    </td>`;

/* vendor/magento/module-sales/view/frontend/email/order_new.html */
var ORDER_NEW_ITEMS_LAYOUT =
    `            {{layout handle="sales_email_order_items" order_id=$order_id area="frontend"}}`;

/* vendor/magento/module-sales/view/frontend/email/order_new.html */
var ORDER_NEW_HEADER_INCLUDE = `{{template config_path="design/email/header_template"}}`;

/* vendor/magento/module-email/view/frontend/email/header.html */
var EMAIL_HEADER_STYLES = `    <style type="text/css">
        {{var template_styles|raw}}

        {{css file="css/email.css"}}
    </style>
</head>
<body>
{{inlinecss file="css/email-inline.css"}}`;

/* vendor/magento/module-email/view/frontend/email/header.html */
var EMAIL_HEADER_LOGO = `                        <a class="logo" href="{{store url=""}}">
                            <img
                                {{if logo_width}}
                                    width="{{var logo_width}}"
                                {{else}}
                                    width="180"
                                {{/if}}

                                {{if logo_height}}
                                    height="{{var logo_height}}"
                                {{/if}}

                                src="{{var logo_url}}"
                                alt="{{var logo_alt}}"
                                border="0"
                            />
                        </a>`;

/* vendor/magento/module-email/view/frontend/email/footer.html */
var EMAIL_FOOTER_CLOSING =
    `                        <p class="closing">{{trans "Thank you, %store_name" store_name=$store.frontend_name}}!</p>`;

/* vendor/magento/theme-frontend-luma/Magento_Customer/email/account_new.html */
var ACCOUNT_NEW_CREDENTIALS = `<p>
    {{trans
        'To sign in to our site, use these credentials during checkout or on the <a href="%customer_url">My Account</a> page:'

        customer_url=$this.getUrl($store,'customer/account/',[_nosid:1])
    |raw}}
</p>
<table class="email-credentials">
    <tr>
        <th>{{trans "Email:"}}</th>
        <td>{{var customer.email}}</td>
    </tr>
</table>`;

/* vendor/magento/theme-frontend-luma/Magento_Customer/email/account_new.html */
var ACCOUNT_NEW_FEATURE_ICON =
    `                        <img src="{{view url='Magento_Customer/images/icn_checkout.png'}}" height="30" width="30" alt="{{trans 'Quick Checkout'}}" />`;

/* vendor/magento/magento2-base/dev/tests/integration/testsuite/Magento/Email/Model/_files/newCustomerAccountEmailTest.html */
var STORE_INFORMATION_LIST = `    <li><strong>{{trans "Store Name:"}}</strong>{{config path="general/store_information/name"}} </li>
    <li><strong>{{trans "Store Phone Number:"}}</strong> {{config path="general/store_information/phone"}}</li>
    <li><strong>{{trans "Country:"}}</strong> {{config path="general/store_information/country_id"}}</li>`;

/* vendor/magento/module-user/view/adminhtml/email/password_reset_confirmation.html */
var ADMIN_PASSWORD_RESET_LINK =
    `{{store url="admin/auth/resetpassword/" _type="web" _query_id=$user.user_id _query_token=$user.rp_token _nosid=1 }}`;

/*
 * The store address block. Magento ships no template using it, but it is the variable the store
 * address is offered as, and it is the reason the escaping story matters: the value carries markup
 * and is unreadable once escaped.
 */
var STORE_ADDRESS_BLOCK = `<p>{{var store.getFormattedAddress()|raw}}</p>`;

/*
 * Synthetic, and only for the length limit.
 *
 * 128 two-byte characters, 256 bytes in all. A cut at 255 bytes would land inside the last one, so
 * this says whether the cut respects character boundaries; a cut counting characters instead of
 * bytes would not even fire.
 */
var OVERLONG_MULTIBYTE_MESSAGE = new Array(129).join(String.fromCharCode(0xE9));

/*
 * Synthetic, and only for the length limit.
 *
 * 254 letters, a space, then one more letter: 256 bytes, with byte 255 being the space. A cut that
 * kept that space would leave an expression the server re-reads as a shorter, trimmed one, so the
 * two sides would disagree about which reference this is.
 */
var OVERLONG_TRAILING_SPACE_MESSAGE = new Array(255).join('a') + ' b';

module.exports = {
    ORDER_NEW_INTRO: ORDER_NEW_INTRO,
    ORDER_NEW_CUSTOMER_NOTE: ORDER_NEW_CUSTOMER_NOTE,
    ORDER_NEW_SHIPPING_METHOD: ORDER_NEW_SHIPPING_METHOD,
    ORDER_NEW_ITEMS_LAYOUT: ORDER_NEW_ITEMS_LAYOUT,
    ORDER_NEW_HEADER_INCLUDE: ORDER_NEW_HEADER_INCLUDE,
    EMAIL_HEADER_STYLES: EMAIL_HEADER_STYLES,
    EMAIL_HEADER_LOGO: EMAIL_HEADER_LOGO,
    EMAIL_FOOTER_CLOSING: EMAIL_FOOTER_CLOSING,
    ACCOUNT_NEW_CREDENTIALS: ACCOUNT_NEW_CREDENTIALS,
    ACCOUNT_NEW_FEATURE_ICON: ACCOUNT_NEW_FEATURE_ICON,
    STORE_INFORMATION_LIST: STORE_INFORMATION_LIST,
    ADMIN_PASSWORD_RESET_LINK: ADMIN_PASSWORD_RESET_LINK,
    STORE_ADDRESS_BLOCK: STORE_ADDRESS_BLOCK,
    OVERLONG_MULTIBYTE_MESSAGE: OVERLONG_MULTIBYTE_MESSAGE,
    OVERLONG_TRAILING_SPACE_MESSAGE: OVERLONG_TRAILING_SPACE_MESSAGE
};
