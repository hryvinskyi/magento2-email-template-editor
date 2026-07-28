/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * What the scanner must say about real template content.
 *
 * Several of these tests assert an answer that looks wrong until you know why it is right. Those
 * carry a note saying which behaviour of the renderer they are copying, because the temptation to
 * "fix" them is exactly the failure they exist to prevent: the span a scanner reports is the span
 * a formatting change is written over, so a scanner that is tidier than the renderer writes into
 * the wrong bytes.
 */
var harness = require('./harness'),
    fixtures = require('./fixtures'),
    test = harness.test,
    assertSame = harness.assertSame,
    assertLike = harness.assertLike,
    assertTrue = harness.assertTrue,
    scanner = harness.loadPureModule('email-editor/knowledge/directive-scanner.js'),
    chain = harness.loadPureModule('email-editor/knowledge/modifier-chain.js'),
    ACCENTED = String.fromCharCode(0xE9);

/**
 * Collect the references of a scan, in order
 *
 * @param {Object[]} occurrences
 * @return {string[]}
 */
function referencesOf(occurrences) {
    return occurrences.map(function (occurrence) {
        return occurrence.reference;
    });
}

/**
 * Scan and insist on exactly one occurrence
 *
 * @param {string} text
 * @return {Object}
 */
function onlyOccurrence(text) {
    var occurrences = scanner.scan(text);

    assertSame(occurrences.length, 1, 'occurrence count in ' + JSON.stringify(text));

    return occurrences[0];
}

test('a directive with no chain has an empty modifier span sitting just after its path', function () {
    var text = fixtures.ORDER_NEW_SHIPPING_METHOD,
        occurrences = scanner.scan(text),
        variable = occurrences[1],
        directive = '{{var order.shipping_description';

    assertLike(
        referencesOf(occurrences),
        ['trans:Shipping Method', 'var:order.shipping_description', 'if:', 'var:shipping_msg'],
        'references'
    );
    assertLike(variable.modifiers, [], 'modifier calls');
    assertSame(variable.modifierText, '', 'modifier text');
    assertSame(variable.chainParsed, true, 'chain parsed');
    assertSame(variable.overlong, false, 'over-long');
    assertSame(variable.modifierStart, text.indexOf(directive) + directive.length, 'modifier start');
    assertSame(variable.modifierEnd, variable.modifierStart, 'modifier end');
});

test('the store address block reports its chain and brackets it exactly', function () {
    var text = fixtures.STORE_ADDRESS_BLOCK,
        occurrence = onlyOccurrence(text);

    assertSame(occurrence.reference, 'var:store.getFormattedAddress()', 'reference');
    assertSame(occurrence.kind, 'var', 'kind');
    assertSame(occurrence.expression, 'store.getFormattedAddress()', 'expression');
    assertSame(occurrence.modifierText, '|raw', 'modifier text');
    assertLike(occurrence.modifiers, [{name: 'raw', args: []}], 'modifier calls');
    assertSame(occurrence.chainParsed, true, 'chain parsed');
    assertSame(text.slice(occurrence.modifierStart, occurrence.modifierEnd), '|raw', 'bracketed chain');
    assertSame(text.slice(occurrence.start, occurrence.end), '{{var store.getFormattedAddress()|raw}}', 'span');
});

test('a chain of several calls decomposes in the order the renderer applies them', function () {
    var occurrences = scanner.scan(fixtures.ORDER_NEW_CUSTOMER_NOTE),
        variable = occurrences[1];

    assertLike(referencesOf(occurrences), ['depend:', 'var:order_data.email_customer_note'], 'references');
    assertSame(variable.modifierText, '|escape|nl2br', 'modifier text');
    assertLike(
        variable.modifiers,
        [{name: 'escape', args: []}, {name: 'nl2br', args: []}],
        'modifier calls'
    );
    assertSame(variable.chainParsed, true, 'chain parsed');
});

test('a modifier call carries its arguments', function () {
    var occurrence = onlyOccurrence('{{var customer.name|escape:html|nl2br}}');

    assertLike(
        occurrence.modifiers,
        [{name: 'escape', args: ['html']}, {name: 'nl2br', args: []}],
        'modifier calls'
    );
    assertSame(occurrence.chainParsed, true, 'chain parsed');
});

test('a config directive is identified by its path parameter', function () {
    var occurrences = scanner.scan(fixtures.STORE_INFORMATION_LIST);

    assertLike(
        referencesOf(occurrences),
        [
            'trans:Store Name:',
            'config:general/store_information/name',
            'trans:Store Phone Number:',
            'config:general/store_information/phone',
            'trans:Country:',
            'config:general/store_information/country_id'
        ],
        'references'
    );
});

test('a translated message is identified by the message, not by its parameters', function () {
    var occurrence = onlyOccurrence(fixtures.EMAIL_FOOTER_CLOSING);

    assertSame(occurrence.reference, 'trans:Thank you, %store_name', 'reference');
    assertSame(occurrence.kind, 'trans', 'kind');
});

test('a directive split over several lines keeps its message and its chain', function () {
    var text = fixtures.ACCOUNT_NEW_CREDENTIALS,
        occurrences = scanner.scan(text),
        message = 'To sign in to our site, use these credentials during checkout or on the ' +
            '<a href="%customer_url">My Account</a> page:';

    assertLike(
        referencesOf(occurrences),
        ['trans:' + message, 'trans:Email:', 'var:customer.email'],
        'references'
    );
    assertSame(occurrences[0].modifierText, '|raw', 'modifier text');
    assertSame(
        text.slice(occurrences[0].modifierStart, occurrences[0].modifierEnd),
        '|raw',
        'bracketed chain'
    );
});

test('a directive ends at the first closing braces, even inside a quoted parameter', function () {
    // The renderer's expression ends a directive at the first `}}` and does not care that it sits
    // inside quotes: `{{trans "a }} b"}}` is the directive `{{trans "a }}` followed by the literal
    // text ` b"}}`. Reading the quotes instead would put the modifier span past the directive's
    // real end, and a formatting change would then be written inside the quoted string, turning a
    // lookup miss into a corrupted template.
    var text = '{{trans "a }} b"}}',
        occurrence = onlyOccurrence(text);

    assertSame(occurrence.end, 13, 'end');
    assertSame(text.slice(occurrence.start, occurrence.end), '{{trans "a }}', 'span');
    assertSame(occurrence.reference, 'trans:', 'reference');
    // Both bounds land in what a reader would call the middle of the quoted string, and that is
    // the answer that matches what renders: the closing quote is never reached, so the message
    // cannot be read and the directive renders as nothing at all.
    assertSame(occurrence.modifierEnd, 10, 'modifier end');
    assertSame(text.slice(0, occurrence.modifierEnd), '{{trans "a', 'text before the chain');
});

test('the chain begins at the first pipe whatever follows it', function () {
    // The email path hands the whole directive body to a two-part split on `|` and has no capture
    // group for the chain, so the newer directive path's `[a-z0-9:_-]+` character class never
    // applies. `escape:HTML` is a chain, and it renders unescaped because the escaping modifier
    // has no arm for that spelling and returns the value untouched.
    var occurrence = onlyOccurrence('{{var x|escape:HTML}}');

    assertSame(occurrence.modifierText, '|escape:HTML', 'modifier text');
    assertLike(occurrence.modifiers, [{name: 'escape', args: ['HTML']}], 'modifier calls');
    assertSame(occurrence.chainParsed, true, 'chain parsed');
});

test('a pipe inside a quoted message is still where the chain starts', function () {
    var text = '{{trans "a|b"}}',
        occurrence = onlyOccurrence(text);

    assertSame(occurrence.modifierText, '|b"', 'modifier text');
    assertSame(occurrence.chainParsed, false, 'chain parsed');
    assertSame(occurrence.reference, 'trans:', 'reference');
});

test('a chain that cannot be read back is reported unparsed but still located exactly', function () {
    var text = '{{var customer.email|escape:HTML | nl2br}}',
        occurrence = onlyOccurrence(text);

    assertSame(occurrence.chainParsed, false, 'chain parsed');
    assertSame(occurrence.modifierText, '|escape:HTML | nl2br', 'modifier text');
    assertSame(
        text.slice(occurrence.modifierStart, occurrence.modifierEnd),
        occurrence.modifierText,
        'bracketed chain'
    );
    assertSame(occurrence.reference, 'var:customer.email', 'reference');
});

test('inserting a chain into a directive with a trailing space never touches the path', function () {
    // The body is split on its first `|`, so an insertion point taken just before the closing
    // braces would hand the variable resolver `order.increment_id ` - path plus space - and the
    // variable would stop resolving.
    var text = '{{var order.increment_id }}',
        occurrence = onlyOccurrence(text),
        rewritten = text.slice(0, occurrence.modifierStart) + '|escape' + text.slice(occurrence.modifierEnd);

    assertSame(occurrence.modifierStart, occurrence.modifierEnd, 'empty chain span');
    assertSame(rewritten, '{{var order.increment_id|escape }}', 'rewritten directive');
    assertSame(occurrence.reference, 'var:order.increment_id', 'reference');
});

test('a quoted and an unquoted parameter are the same reference', function () {
    assertSame(
        onlyOccurrence('{{customVar code=my_code}}').reference,
        onlyOccurrence('{{customVar code="my_code"}}').reference,
        'custom variable reference'
    );
    assertSame(onlyOccurrence('{{customVar code=my_code}}').reference, 'customVar:my_code', 'reference');
    assertSame(
        onlyOccurrence('{{config path=general/store_information/name}}').reference,
        onlyOccurrence('{{config path="general/store_information/name"}}').reference,
        'config reference'
    );
    assertSame(
        onlyOccurrence("{{view url='Magento_Customer/images/icn_checkout.png'}}").reference,
        onlyOccurrence('{{view url="Magento_Customer/images/icn_checkout.png"}}').reference,
        'view reference'
    );
});

test('the directive name matches case-insensitively and folds onto one spelling', function () {
    // The renderer reaches a directive through a reflected method call and PHP method names are
    // case-insensitive, so these all render identically and must describe one thing.
    assertSame(onlyOccurrence('{{customVar code=my_code}}').reference, 'customVar:my_code', 'as published');
    assertSame(onlyOccurrence('{{CustomVar code=my_code}}').reference, 'customVar:my_code', 'capitalised');
    assertSame(onlyOccurrence('{{CUSTOMVAR code=my_code}}').reference, 'customVar:my_code', 'upper case');
    assertSame(onlyOccurrence('{{VAR customer.email}}').kind, 'var', 'kind folded');
});

test('whitespace inside a variable path is not part of its identity', function () {
    assertSame(onlyOccurrence('{{var  spaced.path() }}').reference, 'var:spaced.path()', 'spaced');
    assertSame(onlyOccurrence('{{var spaced.path()}}').reference, 'var:spaced.path()', 'unspaced');
});

test('closing tags and branch markers are not occurrences', function () {
    var occurrences = scanner.scan(fixtures.EMAIL_HEADER_LOGO);

    assertLike(
        referencesOf(occurrences),
        ['store:', 'if:', 'var:logo_width', 'if:', 'var:logo_height', 'var:logo_url', 'var:logo_alt'],
        'references'
    );
});

test('directives nested inside a condition are reachable in their own right', function () {
    var references = referencesOf(scanner.scan(fixtures.ORDER_NEW_CUSTOMER_NOTE));

    assertSame(references.length, 2, 'occurrence count');
    assertSame(references[1], 'var:order_data.email_customer_note', 'nested variable');
});

test('the kinds identified by a parameter each read their own', function () {
    assertSame(
        onlyOccurrence(fixtures.ORDER_NEW_ITEMS_LAYOUT).reference,
        'layout:sales_email_order_items',
        'layout handle'
    );
    assertSame(
        onlyOccurrence(fixtures.ORDER_NEW_HEADER_INCLUDE).reference,
        'template:design/email/header_template',
        'template config path'
    );
    assertSame(
        onlyOccurrence(fixtures.ADMIN_PASSWORD_RESET_LINK).reference,
        'store:admin/auth/resetpassword/',
        'store url'
    );
    assertLike(
        referencesOf(scanner.scan(fixtures.ACCOUNT_NEW_FEATURE_ICON)),
        ['view:Magento_Customer/images/icn_checkout.png', 'trans:Quick Checkout'],
        'view url and message'
    );
});

test('a block is identified by its class, and by its id when it has no class', function () {
    // A class name carries backslashes, and the renderer's tokenizer keeps a backslash that is not
    // doubled, so the name arrives whole rather than half-unescaped.
    assertSame(
        onlyOccurrence('{{block class="Magento\\Cms\\Block\\Widget\\Block" block_id="1"}}').reference,
        'block:Magento\\Cms\\Block\\Widget\\Block',
        'class'
    );
    assertSame(onlyOccurrence('{{block id=footer_links}}').reference, 'block:footer_links', 'id');
});

test('a media directive is identified by its url', function () {
    assertSame(
        onlyOccurrence('{{media url="wysiwyg/banner.jpg"}}').reference,
        'media:wysiwyg/banner.jpg',
        'media url'
    );
});

test('the kinds with no identity of their own carry the kind alone', function () {
    var references = referencesOf(scanner.scan(fixtures.EMAIL_HEADER_STYLES));

    assertLike(references, ['var:template_styles', 'css:css/email.css', 'inlinecss:'], 'references');
    assertSame(onlyOccurrence('{{protocol url="www.example.com/"}}').reference, 'protocol:', 'protocol');
    assertSame(onlyOccurrence('{{depend store_phone}}').reference, 'depend:', 'depend');
    assertSame(onlyOccurrence('{{for item in order.items}}').reference, 'for:', 'for');
});

test('a directive whose key the server would refuse is not reported', function () {
    // A brace in the expression and an unpublished kind are both refused outright by the server,
    // so neither may be shown as answerable here.
    assertSame(scanner.scan('<p>{{var order.{id}}}</p>').length, 0, 'brace in the expression');
    assertSame(scanner.scan('<p>{{banana harvest=now}}</p>').length, 0, 'unpublished kind');
    assertSame(scanner.scan('<p>{{else}}</p>').length, 0, 'branch marker');
});

test('an over-long expression is cut on a character boundary rather than dropped', function () {
    var occurrence = onlyOccurrence('{{trans "' + fixtures.OVERLONG_MULTIBYTE_MESSAGE + '"}}');

    assertSame(occurrence.overlong, true, 'over-long');
    assertSame(occurrence.expression, new Array(128).join(ACCENTED), 'expression');
    assertSame(Buffer.byteLength(occurrence.expression, 'utf8'), 254, 'expression bytes');
    assertSame(occurrence.reference, 'trans:' + occurrence.expression, 'reference');
});

test('an over-long expression cut just after a space loses that space', function () {
    var occurrence = onlyOccurrence('{{trans "' + fixtures.OVERLONG_TRAILING_SPACE_MESSAGE + '"}}');

    assertSame(occurrence.overlong, true, 'over-long');
    assertSame(occurrence.expression, new Array(255).join('a'), 'expression');
});

test('an expression that fits is not flagged', function () {
    assertSame(onlyOccurrence(fixtures.EMAIL_FOOTER_CLOSING).overlong, false, 'over-long');
});

test('scanning a single directive range reports it with document offsets', function () {
    var text = fixtures.ORDER_NEW_SHIPPING_METHOD,
        whole = scanner.scan(text),
        variable = whole[1],
        ranged = scanner.scanRange(text, variable.start, variable.end);

    assertSame(ranged.length, 1, 'occurrence count');
    assertLike(ranged[0], variable, 'occurrence');
    assertSame(ranged[0].start, variable.start, 'document offset kept');
});

test('a range cutting a directive in half reports nothing', function () {
    var text = fixtures.STORE_ADDRESS_BLOCK,
        occurrence = onlyOccurrence(text);

    assertSame(scanner.scanRange(text, occurrence.start, occurrence.end - 1).length, 0, 'clipped end');
    assertSame(scanner.scanRange(text, occurrence.start + 1, occurrence.end).length, 0, 'clipped start');
});

test('every occurrence brackets exactly the chain it reports', function () {
    var texts = Object.keys(fixtures).map(function (name) {
            return fixtures[name];
        }),
        checked = 0;

    texts.forEach(function (text) {
        scanner.scan(text).forEach(function (occurrence) {
            assertSame(text.slice(occurrence.start, occurrence.start + 2), '{{', 'directive opens');
            assertSame(text.slice(occurrence.end - 2, occurrence.end), '}}', 'directive closes');
            assertSame(
                text.slice(occurrence.modifierStart, occurrence.modifierEnd),
                occurrence.modifierText,
                'chain bytes'
            );
            assertTrue(occurrence.modifierStart >= occurrence.start + 2, 'chain starts inside the directive');
            assertTrue(occurrence.modifierEnd <= occurrence.end - 2, 'chain ends inside the directive');
            checked++;
        });
    });

    assertTrue(checked > 20, 'fixtures produced occurrences to check');
});

test('a decomposed chain agrees with the chain module both ways', function () {
    // The two modules spell the same grammar twice, because a module holding pure logic depends on
    // nothing. This is what keeps the two spellings from drifting apart unnoticed.
    var texts = Object.keys(fixtures).map(function (name) {
            return fixtures[name];
        }),
        checked = 0;

    texts.concat([
        '{{var customer.name|escape:html|nl2br}}',
        '{{var x|escape:HTML}}'
    ]).forEach(function (text) {
        scanner.scan(text).forEach(function (occurrence) {
            if (!occurrence.chainParsed || occurrence.modifierText === '') {
                return;
            }

            assertLike(chain.parse(occurrence.modifierText), occurrence.modifiers, 'parsed chain');
            assertSame(chain.serialise(occurrence.modifiers), occurrence.modifierText, 'serialised chain');
            checked++;
        });
    });

    assertTrue(checked > 3, 'fixtures produced chains to check');
});

test('re-reading a range confirms the directive a caller is still looking at', function () {
    var text = fixtures.STORE_ADDRESS_BLOCK,
        occurrence = onlyOccurrence(text),
        result = scanner.rederive(text, occurrence.start, occurrence.end, occurrence);

    assertSame(result.reason, 'ok', 'reason');
    assertLike(result.occurrence, occurrence, 'occurrence');
});

test('re-reading answers about the document as it is now, not as the caller last saw it', function () {
    // The whole point of asking again. The caller's offsets were measured before the insertion and
    // every one of them is now short by its length; writing over the chain with them would replace
    // bytes that belong to text the caller never looked at.
    var original = fixtures.STORE_ADDRESS_BLOCK,
        stale = onlyOccurrence(original),
        inserted = '<h1>Hello</h1>\n',
        text = inserted + original,
        result = scanner.rederive(
            text,
            stale.start + inserted.length,
            stale.end + inserted.length,
            stale
        );

    assertSame(result.reason, 'ok', 'reason');
    assertSame(result.occurrence.modifierStart, stale.modifierStart + inserted.length, 'chain start moved');
    assertSame(result.occurrence.modifierEnd, stale.modifierEnd + inserted.length, 'chain end moved');
    assertSame(
        text.slice(result.occurrence.modifierStart, result.occurrence.modifierEnd),
        '|raw',
        'the confirmed span is the chain'
    );
    assertTrue(
        text.slice(stale.modifierStart, stale.modifierEnd) !== '|raw',
        'the caller\'s own offsets no longer point at the chain'
    );
});

test('a range holding no directive at all says so rather than being written into', function () {
    var text = fixtures.STORE_ADDRESS_BLOCK,
        occurrence = onlyOccurrence(text),
        emptied = '<p>the directive was deleted</p>',
        result = scanner.rederive(emptied, 0, emptied.length, occurrence);

    assertSame(result.reason, 'gone', 'reason');
    assertSame(result.occurrence, null, 'no span to write with');
});

test('a range holding a different directive is a refusal', function () {
    var directive = '{{var customer.email|raw}}',
        occurrence = onlyOccurrence('<p>{{var customer.name|raw}}</p>'),
        replaced = '<p>' + directive + '</p>',
        result = scanner.rederive(replaced, 3, 3 + directive.length, occurrence);

    assertSame(result.reason, 'changed', 'reason');
    assertSame(result.occurrence, null, 'no span to write with');
});

test('a chain edited in the document behind the caller is a refusal', function () {
    // The chain the caller last displayed is part of what is matched, so a chain the admin typed
    // over by hand is never silently replaced by whatever the popover still shows.
    var text = '<p>{{var customer.name|escape}}</p>',
        occurrence = onlyOccurrence(text),
        result = scanner.rederive(text, occurrence.start, occurrence.end, {
            reference: occurrence.reference,
            modifierText: '|raw'
        });

    assertSame(result.reason, 'changed', 'reason');
    assertSame(result.occurrence, null, 'no span to write with');
});

test('a range that has grown to hold two directives is a refusal', function () {
    var text = '<p>{{var a}}{{var b}}</p>',
        result = scanner.rederive(text, 3, 21, {reference: 'var:a', modifierText: ''});

    assertSame(result.reason, 'changed', 'reason');
    assertSame(result.occurrence, null, 'no span to write with');
});

test('re-reading with nothing to match against is a refusal', function () {
    var text = fixtures.STORE_ADDRESS_BLOCK,
        occurrence = onlyOccurrence(text);

    assertSame(scanner.rederive(text, occurrence.start, occurrence.end, null).reason, 'changed', 'reason');
});

test('removing every modifier is a chain that may be written', function () {
    assertSame(scanner.isWritableChain(''), true, 'the empty chain');
});

test('a chain that may be written begins at a pipe and reads back unchanged', function () {
    assertSame(scanner.isWritableChain('|escape'), true, 'one call');
    assertSame(scanner.isWritableChain('|escape:html|nl2br'), true, 'a call with an argument and another call');
});

test('bytes that would land outside the chain are never written', function () {
    assertSame(scanner.isWritableChain('escape'), false, 'no leading pipe');
    assertSame(scanner.isWritableChain('|escape}}'), false, 'closing braces');
    assertSame(scanner.isWritableChain('|escape\nnl2br'), false, 'a line break');
});

test('a chain that could not be read back is never written', function () {
    assertSame(scanner.isWritableChain('|escape|'), false, 'a trailing pipe');
    assertSame(scanner.isWritableChain('||escape'), false, 'a repeated pipe');
    assertSame(scanner.isWritableChain('|esc ape'), false, 'a space in a name');
    assertSame(scanner.isWritableChain('|1escape'), false, 'a name starting with a digit');
    assertSame(scanner.isWritableChain(null), false, 'not text at all');
});
