/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Where the directive popover goes, and how tall it may grow there.
 *
 * Single charter: turn a point on screen and a viewport into a place to put a box. Nothing here
 * touches the DOM, reads a real element or knows what the box contains; it is arithmetic about
 * rectangles, which is exactly why it is not left inline in the component where nothing could
 * check it.
 *
 * The box is always inside the viewport. A popover opened near the bottom of the screen would
 * otherwise hang off it, and the part hanging off is the part carrying the buttons - the admin is
 * left looking at an explanation with no way to act on it and no scrollbar to reach one.
 *
 * Which edge is pinned is the reason this can be decided before the box exists. Below the point
 * the top edge is pinned, above it the bottom edge is - so the box's real height, which is not
 * known until its content has arrived and changes again while the admin reads it, never moves the
 * edge that faces the directive. The height that comes back is a ceiling rather than a size: past
 * it the box scrolls its own content, and a popover that grew with a long explanation still ends
 * where it was told to.
 */
define([], function () {
    'use strict';

    var /**
         * How far the box sits from the point it describes, in pixels
         *
         * Far enough that the box does not cover the directive that was clicked, close enough that
         * the two still read as one thing.
         *
         * @type {number}
         */
        GAP = 14,

        /**
         * How close to the edge of the viewport the box may come, in pixels
         *
         * @type {number}
         */
        MARGIN = 8,

        /**
         * The least height worth offering, in pixels
         *
         * Below this the box would be a scrollbar with a sliver of text in it, so the ceiling stops
         * here even when the room does not. What overflows then is clipped by the viewport, which
         * is what any window too small for its content does.
         *
         * @type {number}
         */
        MIN_HEIGHT = 160;

    /**
     * Hold a value between two bounds, preferring the lower one when they cross
     *
     * The bounds cross when the box is wider than the space it has to fit in. Pinning it to the
     * near edge is the only answer that keeps its top-left corner visible, and the top-left corner
     * is where the title and the close button are.
     *
     * @param {number} value
     * @param {number} low
     * @param {number} high
     * @return {number}
     */
    function clamp(value, low, high) {
        if (high < low) {
            return low;
        }

        return Math.min(Math.max(value, low), high);
    }

    /**
     * Read a measurement, treating anything that is not one as zero
     *
     * @param {*} value
     * @return {number}
     */
    function measure(value) {
        return typeof value === 'number' && isFinite(value) ? value : 0;
    }

    /**
     * Decide where to put the popover, and how tall it may be there
     *
     * The height in `size` is what the box would like rather than what it will get: it only picks
     * the side, and the ceiling that comes back is what actually bounds the box.
     *
     * @param {{x: number, y: number}} point where on screen the directive was activated
     * @param {{width: number, height: number}} size how big the box would like to be
     * @param {{width: number, height: number}} viewport the visible area
     * @return {{left: number, top: (number|null), bottom: (number|null), placement: string,
     *          maxHeight: number}} exactly one of `top` and `bottom` is a number; the other is null
     *          and says that edge is the box's own to decide
     */
    function place(point, size, viewport) {
        var x = measure(point && point.x),
            y = measure(point && point.y),
            width = measure(size && size.width),
            height = measure(size && size.height),
            viewportWidth = measure(viewport && viewport.width),
            viewportHeight = measure(viewport && viewport.height),
            roomBelow = viewportHeight - MARGIN - (y + GAP),
            roomAbove = y - GAP - MARGIN,
            left = clamp(x, MARGIN, viewportWidth - MARGIN - width),
            below,
            top,
            bottom;

        if (height <= roomBelow) {
            below = true;
        } else if (height <= roomAbove) {
            below = false;
        } else {
            below = roomBelow >= roomAbove;
        }

        if (below) {
            top = Math.max(MARGIN, y + GAP);

            return {
                left: left,
                top: top,
                bottom: null,
                placement: 'below',
                maxHeight: Math.max(viewportHeight - MARGIN - top, MIN_HEIGHT)
            };
        }

        bottom = Math.max(MARGIN, viewportHeight - (y - GAP));

        return {
            left: left,
            top: null,
            bottom: bottom,
            placement: 'above',
            maxHeight: Math.max(viewportHeight - MARGIN - bottom, MIN_HEIGHT)
        };
    }

    return {
        GAP: GAP,
        MARGIN: MARGIN,
        MIN_HEIGHT: MIN_HEIGHT,
        place: place
    };
});
