<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * A frontend the application serves — a single-page app, a pre-rendered site — that a
 * package prepared (winter-front-nuxt): where its built files are and where it answers
 * ({@see site()}), what the image needs for it, and how it is built for the image. The
 * application declares it with {@see Components::frontend()}.
 *
 * The kernel knows no framework by name: what a framework's build looks like is the
 * package's to say.
 *
 * @link https://winterframe.net/docs/frontend The frontend in the application
 */
interface Frontend
{
    /** Where its built files are and where it answers. */
    public function site(): FrontSite;

    /** What the image needs for it at run time — nothing for a built single-page app. */
    public function image(): ServiceImage;

    /** How the image builds it — a stage of its own; null when it is built elsewhere. */
    public function build(): ?FrontBuild;

    /**
     * Its development server under `call run dev` (`nuxt dev`), which the application's own
     * address then serves; null — none, the built files under `call run dev` too. Asked only
     * under `call run dev`: in production a frontend is its built files.
     */
    public function devServer(): ?FrontProgram;

    /**
     * Advice about its own setup — a config that does not match its mode, a build not made
     * yet — said once, as warnings, when the application starts serving; never a reason to
     * stop. Read nowhere else, so it may look at the disk.
     *
     * @param bool $dev Whether the application runs under `call run dev`: nothing needs
     *     to be built then.
     * @return list<string>
     */
    public function hints(bool $dev): array;
}
