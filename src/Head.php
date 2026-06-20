<?php

namespace BrickPHP;

use BrickPHP\State\StateManager;
use BrickPHP\UI\TagDomNode;
use BrickPHP\VNode\App;

/**
 * Builds the document <head> for a full-page render. Brick assembles the
 * per-request inputs (boot script, style hash, wireframe flag) and hands them
 * here; {@see build()} returns the fully populated <head> TagDomNode.
 *
 * Ordering matters and is encoded here: framework /style.css before user
 * stylesheets before user inline styles (so each later layer can override the
 * previous), and /brick.js before user scripts (so Brick.* is available).
 */
class Head
{
    /**
     * Inline stylesheet for wireframe mode. Loaded only when the page is
     * rendered with ?wireframe=true. Keeps the original element box (so
     * margins/paddings/sizing stay intact) and overlays a dashed outline +
     * a small top-left tag with the construct/component name. The image
     * placeholder uses two CSS gradients to draw the classic crossed-out
     * rectangle so a sized <img> turns into a same-sized X box.
     */
    private const WIREFRAME_CSS = <<<'CSS'
.brick-wf{outline:1px dashed rgba(60,80,120,0.45);outline-offset:-1px;position:relative;}
.brick-wf-label{position:absolute;top:0;left:0;z-index:9999;font:10px/1 ui-monospace,SFMono-Regular,Menlo,monospace;background:#ffec99;color:#222;padding:2px 5px;pointer-events:none;border-bottom-right-radius:3px;white-space:nowrap;letter-spacing:.02em;}
.brick-wf-img{background:
  linear-gradient(to top right,transparent calc(50% - 1px),rgba(60,80,120,0.4) 50%,transparent calc(50% + 1px)),
  linear-gradient(to top left,transparent calc(50% - 1px),rgba(60,80,120,0.4) 50%,transparent calc(50% + 1px));min-height:32px;}
.brick-wf-hover{background-color:rgba(255,235,100,0.45) !important;}
CSS;

    public function __construct(
        private App $entry,
        private StateManager $state,
        private string $bootJs,
        private string $styleHash,
        private bool $wireframe,
        private bool $pushClientState,
    ) {
    }

    public function build(): TagDomNode
    {
        $head = (new TagDomNode('head'))
            ->content(
                (new TagDomNode('meta'))->attr('charset', 'UTF-8'),
                (new TagDomNode('meta'))->attr('name', 'viewport')->attr('content', 'width=device-width, initial-scale=1.0'),
                (new TagDomNode('title'))->rawContent(htmlspecialchars($this->entry->title())),
                // Declared so browsers don't blindly probe /favicon.ico on
                // every page (which would rewrite to index.php and force a
                // full app render for an icon poll). Served as a static file
                // from /favicon.ico via Apache's `!-f` guard.
                (new TagDomNode('link'))->attr('rel', 'icon')->attr('type', 'image/x-icon')->attr('href', '/favicon.ico'),
                (new TagDomNode('script'))->rawContent($this->bootJs),
                // /style.css bundles preflight + the extracted utility rules
                // (in that order, so application rules win).
                (new TagDomNode('link'))->attr('rel', 'stylesheet')->attr('href', '/style.css?h=' . $this->styleHash),
            );

        // User-registered external stylesheets — placed after /style.css so
        // their rules can override framework defaults.
        foreach ($this->entry->getStyles() as $href) {
            $head->content((new TagDomNode('link'))->attr('rel', 'stylesheet')->attr('href', $href));
        }
        // User inline styles after externals, so they override.
        $inlineStyles = implode("\n", $this->entry->getStylesInline());
        if ($inlineStyles !== '') {
            $head->content((new TagDomNode('style'))->rawContent($inlineStyles));
        }

        // Framework runtime first so Brick.* is available to user scripts.
        $head->content((new TagDomNode('script'))->attr('src', '/brick.js')->rawContent(''));
        foreach ($this->entry->getScripts() as $script) {
            $tag = (new TagDomNode('script'))->attr('src', $script['src']);
            if ($script['defer']) {
                $tag->attr('defer', 'defer');
            }
            $head->content($tag->rawContent(''));
        }
        $inlineScripts = implode("\n", $this->entry->getScriptsInline());
        if ($inlineScripts !== '') {
            $head->content((new TagDomNode('script'))->rawContent($inlineScripts));
        }

        if ($this->wireframe) {
            $head->content((new TagDomNode('style'))->rawContent(self::WIREFRAME_CSS));
        }

        $stateJs = $this->state->getClientJs();
        if ($stateJs !== null) {
            $head->content((new TagDomNode('script'))->rawContent($stateJs));

            // After a full-page (client=false) navigation, the server already
            // applied the event to client-side state — push it into storage so
            // the localStorage/sessionStorage manager reflects the change
            // instead of reloading the pre-event value. Runs right after the
            // handler is registered; server-side managers return null here and
            // emit nothing.
            if ($this->pushClientState) {
                $clientState = $this->state->getClientState();
                if ($clientState !== null) {
                    $head->content((new TagDomNode('script'))->rawContent(
                        'Brick.setAll(' . json_encode($clientState) . ');'
                    ));
                }
            }
        }

        return $head;
    }
}
