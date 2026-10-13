/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * How long an action that stores or sends Tailwind CSS waits for the compile of its own content.
 *
 * Single charter: turn "the compile answered", "the compile is slow" and "the compile never
 * answered" into exactly one of two outcomes, answered or gave up, and say once in between that it
 * is slow. Nothing here knows what is being compiled, what an action does with the answer or how a
 * timer is made; all three are handed in.
 *
 * Giving up is an outcome of its own rather than a quiet "carry on". An action that went ahead
 * after the wait ran out would store the stylesheet of an earlier revision beside the content of
 * this one and report the draft clean, so nothing would ever store the right one. The action that
 * gave up stores nothing and leaves the draft dirty instead, and the next attempt waits again.
 */
define([], function () {
    'use strict';

    return {
        /**
         * Wait for a compile to answer, saying when it is slow and giving up past a ceiling
         *
         * Exactly one of `answered` and `gaveUp` is called, once. `slow` is called at most once and
         * only while neither has been. A compile that has already answered calls `answered` before
         * this returns, so the common case gains no tick.
         *
         * @param {function(Function): void} onAnswer registers the callback the compile calls once it settles
         * @param {{slowAfter: number, giveUpAfter: number}} limits milliseconds
         * @param {{set: function(Function, number): *, clear: function(*): void}} timers
         * @param {{slow: Function, answered: Function, gaveUp: Function}} outcome
         * @return {void}
         */
        wait: function (onAnswer, limits, timers, outcome) {
            var finished = false,
                slowTimer,
                giveUpTimer;

            /**
             * Settle the wait with one outcome, ignoring every later one
             *
             * @param {Function} handler
             * @return {void}
             */
            function finish(handler) {
                if (finished) {
                    return;
                }

                finished = true;
                timers.clear(slowTimer);
                timers.clear(giveUpTimer);
                handler();
            }

            slowTimer = timers.set(function () {
                if (!finished) {
                    outcome.slow();
                }
            }, limits.slowAfter);

            giveUpTimer = timers.set(function () {
                finish(outcome.gaveUp);
            }, limits.giveUpAfter);

            onAnswer(function () {
                finish(outcome.answered);
            });
        }
    };
});
