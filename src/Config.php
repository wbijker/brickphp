<?php

namespace BrickPHP;

/**
 * Per-app configuration passed to {@see Brick::run()} / {@see Brick::watch()}.
 * The consuming app constructs one of these and hands it to the framework.
 */
class Config
{
    /**
     * When true the page emits the HMR long-poll, the ctrl+click
     * open-in-editor inspector, the "w" wireframe keybind, and the
     * ?wireframe view. Set false in production.
     */
    public bool $development = false;

    /**
     * Root of hmr.php's source-change walk. Relative paths resolve
     * against the entry script's directory; '..' means the project root.
     */
    public string $sourceDir = '..';

    /**
     * Basenames pruned from that walk — matches directory and file names.
     * @var string[]
     */
    public array $sourceExclude = ['vendor', 'node_modules', '.git'];

    /**
     * Editor jump-to-source URL template for ctrl+click, interpolated
     * with {file}, {line}, {col}. Empty disables it. Examples:
     *   phpstorm://open?file={file}&line={line}
     *   vscode://file/{file}:{line}:{col}
     *   cursor://file/{file}:{line}:{col}
     */
    public string $editorUrl = '';

    /**
     * Host-side absolute path of this project, used to rewrite
     * server-captured paths so the editor link resolves when PHP runs
     * in a container/VM. Null = no rewrite (auto-detected server root).
     */
    public ?string $editorHostRoot = '';
}
