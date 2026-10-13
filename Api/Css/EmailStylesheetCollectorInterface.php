<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\EmailTemplateEditor\Api\Css;

/**
 * The stylesheet an email is styled with, gathered from every template that renders into it
 *
 * An email is assembled from several templates - the message itself and the header and footer it
 * includes - and each of them may carry editor CSS of its own. CSS can only be inlined into a
 * complete document, so no template applies its own: each render opens a scope, the templates it
 * includes hand their CSS up into it, and only the outermost render receives the whole sheet to
 * inline once, into the finished email.
 *
 * A sheet has three tiers, applied in this order so that a later tier wins a tie: the theme's
 * generated CSS, the compiled utility CSS, and hand-written custom CSS. Within a tier the
 * including template comes after the templates it includes, so a message outranks its header.
 * A piece of CSS that several templates carry - the theme they share, above all - is kept once.
 */
interface EmailStylesheetCollectorInterface
{
    /**
     * Give a template its own stylesheet, to be collected when that template is rendered
     *
     * Held weakly by the template object, so it lives exactly as long as the template does and can
     * never be picked up by another one. Assigning again replaces the previous sheet.
     *
     * @param object $template The template object that will be rendered
     * @param string $themeCss CSS generated from the theme
     * @param string $utilityCss Compiled utility CSS
     * @param string $customCss Hand-written CSS
     * @return void
     */
    public function assign(object $template, string $themeCss, string $utilityCss, string $customCss): void;

    /**
     * Open a scope for one template render
     *
     * @return void
     */
    public function begin(): void;

    /**
     * Close the innermost scope, adding the rendered template's own stylesheet to it last
     *
     * When another render encloses this one, the scope's CSS moves into it and nothing is returned:
     * the enclosing render is the one that inlines. The outermost render receives the whole sheet.
     *
     * @param object $template The template whose render the scope belongs to
     * @return array{theme:string,utility:string,custom:string}|null Tiers to inline, or null
     *         when an enclosing render will inline them
     * @throws \LogicException When no scope is open
     */
    public function end(object $template): ?array;
}
