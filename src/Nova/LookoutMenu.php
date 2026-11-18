<?php

namespace Lookout\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\URL;
use Lookout\Lookout;

/**
 * A Nova sidebar link to Lookout, for apps that add it to their own main menu:
 * Nova::mainMenu(fn () => [..., LookoutMenu::make()]). Seen only by users the app is open to.
 */
class LookoutMenu
{
    /**
     * A plain link out of Nova: the page answers an Inertia visit with a full-page location change.
     */
    public static function make(string $label = 'Lookout', string $icon = 'lightning-bolt'): MenuSection
    {
        return MenuSection::make($label)
            ->path(URL::remote(url(Lookout::path())))
            ->icon($icon)
            ->canSee(fn (Request $request) => Lookout::visibleTo($request));
    }
}
