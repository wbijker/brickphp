<?php

namespace BrickPHP\UI;

/**
 * Static factory for creating UI elements.
 *
 * Usage:
 *   UI::column()
 *       ->gap(Unit::base())
 *       ->content(
 *           UI::text("Hello")->bold(),
 *           UI::text("World")
 *       )
 */
class UI
{
    // ============================================================
    // Layout Components
    // ============================================================

    public static function column(): Column
    {
        return new Column();
    }

    public static function row(): Row
    {
        return new Row();
    }

    public static function layers(): Layers
    {
        return new Layers();
    }

    public static function stack(): Stack
    {
        return new Stack();
    }

    public static function grid(?int $columns = null): Grid
    {
        return new Grid($columns);
    }

    public static function gridItem(DomNode|\BrickPHP\VNode\VNode|string|null ...$children): GridItem
    {
        $item = new GridItem();
        if ($children !== []) {
            $item->content(...$children);
        }
        return $item;
    }

    public static function inlined(): Inlined
    {
        return new Inlined();
    }

    public static function unconstrained(): Unconstrained
    {
        return new Unconstrained();
    }

    public static function multiColumn(int $count = 2): MultiColumn
    {
        return new MultiColumn($count);
    }

    public static function container(): Container
    {
        return new Container();
    }

    // ============================================================
    // Semantic Elements
    // ============================================================

    public static function section(): Section
    {
        return new Section();
    }

    public static function nav(): Nav
    {
        return new Nav();
    }

    public static function header(): Header
    {
        return new Header();
    }

    public static function footer(): Footer
    {
        return new Footer();
    }

    public static function figure(): Figure
    {
        return new Figure();
    }

    public static function details(): Details
    {
        return new Details();
    }

    public static function dialog(): Dialog
    {
        return new Dialog();
    }

    // ============================================================
    // Content Components
    // ============================================================

    public static function text(string $content): Text
    {
        return new Text($content);
    }

    public static function heading(string $content, int $level = 1): Text
    {
        return new Text($content);
    }

    public static function paragraph(string $content): Text
    {
        return new Text($content);
    }

    public static function image(string $src, string $alt = ''): Image
    {
        return new Image($src, $alt);
    }

    public static function button(string $label): Button
    {
        return new Button($label);
    }

    public static function link(string $href, ?string $label = null): Link
    {
        return new Link($href, $label);
    }

    public static function svg(): Svg
    {
        return new Svg();
    }

    // ============================================================
    // Table Components
    // ============================================================

    public static function table(): Table
    {
        return new Table();
    }

    public static function tableRow(TableCell|TableHeading ...$cells): TableRow
    {
        return new TableRow(...$cells);
    }

    // ============================================================
    // Form Components
    // ============================================================

    public static function form(): Form
    {
        return new Form();
    }

    public static function input(): Input
    {
        return new Input();
    }

    public static function textarea(): Textarea
    {
        return new Textarea();
    }

    public static function select(): Select
    {
        return new Select();
    }

    public static function option(string $label, ?string $value = null): Option
    {
        return new Option($label, $value);
    }

    public static function optgroup(string $label): Optgroup
    {
        return new Optgroup($label);
    }

    public static function label(?string $text = null): Label
    {
        return new Label($text);
    }

    public static function fieldset(): Fieldset
    {
        return new Fieldset();
    }

    public static function output(): Output
    {
        return new Output();
    }

    public static function progress(): Progress
    {
        return new Progress();
    }

    public static function meter(): Meter
    {
        return new Meter();
    }

    // ============================================================
    // List Components
    // ============================================================

    public static function ul(): Ul
    {
        return new Ul();
    }

    public static function ol(): Ol
    {
        return new Ol();
    }

    public static function li(string|UIElement|null $content = null): Li
    {
        return new Li($content);
    }

    public static function dt(string|UIElement|null $content = null): Dt
    {
        return new Dt($content);
    }

    public static function dd(string|UIElement|null $content = null): Dd
    {
        return new Dd($content);
    }

    // ============================================================
    // Media Components
    // ============================================================

    public static function video(): Video
    {
        return new Video();
    }

    public static function audio(): Audio
    {
        return new Audio();
    }

    public static function source(string $src): Source
    {
        return new Source($src);
    }

    public static function track(string $src): Track
    {
        return new Track($src);
    }

    public static function iframe(): Iframe
    {
        return new Iframe();
    }

    public static function canvas(): Canvas
    {
        return new Canvas();
    }

    public static function picture(): Picture
    {
        return new Picture();
    }

    // ============================================================
    // Inline/Text Components
    // ============================================================

    public static function code(string $content = ''): Code
    {
        return new Code($content);
    }

    public static function pre(?string $content = null): Pre
    {
        return new Pre($content);
    }

    public static function blockquote(?string $content = null): Blockquote
    {
        return new Blockquote($content);
    }

    // ============================================================
    // Misc Elements
    // ============================================================

    public static function br(): Br
    {
        return new Br();
    }

    public static function hr(): Hr
    {
        return new Hr();
    }

    public static function span(string|UIElement|null $content = null): Span
    {
        return new Span($content);
    }

    public static function div(): Div
    {
        return new Div();
    }

    // ============================================================
    // Utility Components
    // ============================================================

    public static function spacer(): Container
    {
        $spacer = new Container();
        $spacer->extend();
        return $spacer;
    }

    public static function divider(): Container
    {
        $divider = new Container();
        $divider->extendX();
        $divider->height(Unit::px(1));
        $divider->background(Color::gray(200));
        return $divider;
    }

    // ============================================================
    // Preset Components
    // ============================================================

    public static function card(): Container
    {
        $card = new Container();
        $card->background(Color::white());
        $card->rounded(Unit::roundedLg());
        $card->shadow(Shadow::Medium);
        $card->padding(Unit::medium());
        return $card;
    }

    public static function badge(string $text): Text
    {
        return (new Text($text))
            ->fontSize(FontSize::ExtraSmall)
            ->weight(FontWeight::Medium)
            ->padding(Unit::xs())
            ->paddingX(Unit::small())
            ->roundedFull()
            ->background(Color::gray(100))
            ->color(Color::gray(800));
    }

    public static function pill(string $text): Text
    {
        return (new Text($text))
            ->fontSize(FontSize::Small)
            ->padding(Unit::small())
            ->paddingX(Unit::medium())
            ->roundedFull()
            ->background(Color::blue(500))
            ->color(Color::white());
    }

    public static function avatar(string $src, string $alt = ''): Image
    {
        return (new Image($src, $alt))
            ->roundedFull()
            ->objectCover();
    }

    public static function center(): Row
    {
        return (new Row())->center();
    }

    // ============================================================
    // Routing
    // ============================================================

    public static function router(): Router
    {
        return new Router();
    }

    // ============================================================
    // Style Output
    // ============================================================

    /**
     * Render a stylesheet from a set of self-describing class names (as returned
     * by {@see \BrickPHP\UI\DomNode::collectStyles()}).
     *
     * @param array<string,mixed> $styles class-name set (keys are the names)
     */
    public static function printStyles(array $styles): string
    {
        return \BrickPHP\Style\CssClass::sheet(array_keys($styles));
    }
}
