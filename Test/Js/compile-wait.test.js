/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * How long a save waits for the Tailwind compile of what it is saving.
 *
 * The rule being proved is that running out of patience is never mistaken for an answer. A save
 * that carried on after its wait expired stored the stylesheet of an earlier revision beside the
 * content of this one and reported the draft clean, so the right stylesheet was never stored.
 */
var harness = require('./harness'),
    browserStub = require('./browser-stub'),
    test = harness.test,
    assertLike = harness.assertLike,
    compileWait = harness.loadPureModule('email-editor/tailwind/compile-wait.js'),
    LIMITS = {slowAfter: 2000, giveUpAfter: 15000};

/**
 * Start a wait against a compile the test answers by hand
 *
 * @param {boolean} [answeredAlready] whether the compile has answered before the wait starts
 * @return {{events: string[], clock: Object, answer: Function}}
 */
function start(answeredAlready) {
    var clock = browserStub.create().clock,
        events = [],
        answer = null;

    compileWait.wait(
        function (answered) {
            if (answeredAlready) {
                answered();

                return;
            }

            answer = answered;
        },
        LIMITS,
        {set: clock.setTimeout, clear: clock.clearTimeout},
        {
            slow: function () {
                events.push('slow');
            },
            answered: function () {
                events.push('answered');
            },
            gaveUp: function () {
                events.push('gave up');
            }
        }
    );

    return {
        events: events,
        clock: clock,
        answer: function () {
            answer();
        }
    };
}

test('a compile that has already answered goes ahead at once and arms nothing', function () {
    var wait = start(true);

    assertLike(wait.events, ['answered'], 'outcome before any time passes');
    wait.clock.tick(60000);
    assertLike(wait.events, ['answered'], 'outcome once every limit has passed');
});

test('a slow compile is reported slow and still waited for', function () {
    var wait = start(false);

    wait.clock.tick(2000);
    assertLike(wait.events, ['slow'], 'outcome at the slow mark');

    wait.answer();
    assertLike(wait.events, ['slow', 'answered'], 'outcome once the compile answers');

    wait.clock.tick(60000);
    assertLike(wait.events, ['slow', 'answered'], 'nothing more after the ceiling');
});

test('a compile that never answers gives up instead of going ahead', function () {
    var wait = start(false);

    wait.clock.tick(15000);
    assertLike(wait.events, ['slow', 'gave up'], 'outcome at the ceiling');

    wait.answer();
    assertLike(wait.events, ['slow', 'gave up'], 'a late answer changes nothing');
});
