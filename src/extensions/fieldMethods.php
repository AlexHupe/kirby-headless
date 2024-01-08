<?php

use Kirby\Cms\Block;
use Kirby\Content\Field;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Dom;
use Kirby\Uuid\Uuid;

$filesFieldResolver = function (Block $block) {
    $kirby = $block->kirby();
    $blocks = $kirby->option('blocksResolver.files', ['image' => 'image']);

    // If the block type isn't one to be resolved, return early
    if (!isset($blocks[$block->type()])) {
        return $block;
    }

    // Get the resolvers config
    $resolvers = $kirby->option('blocksResolver.resolvers', []);
    $defaultResolver = $kirby->option('blocksResolver.defaultResolvers.files', fn (\Kirby\Cms\File $image) => [
        'url' => $image->url(),
        'width' => $image->width(),
        'height' => $image->height(),
        'srcset' => $image->srcset(),
        'alt' => $image->alt()->value()
    ]);

    $fieldKeys = $blocks[$block->type()];
    $fieldKeys = is_array($fieldKeys) ? $fieldKeys : [$fieldKeys];

    foreach ($fieldKeys as $key) {
        /** @var \Kirby\Cms\Files $images */
        $images = $block->content()->get($key)->toFiles();

        if ($images->count() === 0) {
            continue;
        }

        // If part of custom resolver, skip
        if (isset($resolvers[$block->type() . ':' . $key])) {
            continue;
        }

        // Get already resolved images
        $resolved = $block->content()->get('resolved')->or([])->value();

        $block->content()->update([
            'resolved' => array_merge($resolved, [
                strtolower($key) => $images->map($defaultResolver)->values()
            ])
        ]);
    }

    return $block;
};

$pagesFieldResolver = function (Block $block) {
    $kirby = $block->kirby();
    $blocks = $kirby->option('blocksResolver.pages', []);

    // If the block type isn't one to be resolved, return early
    if (!isset($blocks[$block->type()])) {
        return $block;
    }

    // Get the resolver method
    $resolvers = $kirby->option('blocksResolver.resolvers', []);
    $defaultResolver = $kirby->option('blocksResolver.defaultResolvers.pages', fn (\Kirby\Cms\Page $page) => [
        'uri' => $page->uri(),
        'title' => $page->title()->value()
    ]);

    $fieldKeys = $blocks[$block->type()];
    $fieldKeys = is_array($fieldKeys) ? $fieldKeys : [$fieldKeys];

    foreach ($fieldKeys as $key) {
        /** @var \Kirby\Cms\Pages $pages */
        $pages = $block->content()->get($key)->toPages();

        if ($pages->count() === 0) {
            continue;
        }

        // If part of custom resolver, skip
        if (isset($resolvers[$block->type() . ':' . $key])) {
            continue;
        }

        // Get already resolved images
        $resolved = $block->content()->get('resolved')->or([])->value();

        $block->content()->update([
            'resolved' => array_merge($resolved, [
                strtolower($key) => $pages->map($defaultResolver)->values()
            ])
        ]);
    }

    return $block;
};

// Support any field type
$customFieldResolver = function (Block $block) {
    $kirby = $block->kirby();
    $resolvers = $kirby->option('blocksResolver.resolvers', []);

    foreach ($resolvers as $identifier => $resolver) {
        [$blockType, $key] = explode(':', $identifier);

        if ($block->type() !== $blockType) {
            continue;
        }

        $resolved = $block->content()->get('resolved')->or([])->value();
        $field = $block->content()->get($key);

        $block->content()->update([
            'resolved' => array_merge($resolved, [
                strtolower($key) => $resolver($field, $block)
            ])
        ]);
    }

    return $block;
};

$nestedBlocksFieldResolver = function (Block $block) use ($filesFieldResolver) {
    /** @var Block $block */
    $kirby = $block->kirby();
    $nestedBlocks = $kirby->option('blocksResolver.nested', ['prose']);
    $blocksKeys = array_intersect($block->content()->keys(), $nestedBlocks);

    foreach ($blocksKeys as $key) {
        $field = $block->content()->get($key);

        $block->content()->update([
            $key => $field->toBlocks()->map($filesFieldResolver)->toArray()
        ]);
    }

    return $block;
};

return [
    /**
     * Resolves page and file permalinks in anchor tags
     *
     * @kql-allowed
     */
    'resolvePermalinks' => function (Field $field) {
        $kirby = $field->parent()->kirby();
        $urlParser = $kirby->option('permalinksResolver.urlParser', fn (string $url, \Kirby\Cms\App $kirby) => $url);

        if ($field->isNotEmpty()) {
            $dom = new Dom($field->value);
            $attributes = ['href', 'src'];
            $elements = $dom->query('//*[' . implode(' | ', A::map($attributes, fn ($attribute) => '@' . $attribute)) . ']');

            foreach ($elements as $element) {
                foreach ($attributes as $attribute) {
                    if ($element->hasAttribute($attribute) && $url = $element->getAttribute($attribute)) {
                        try {
                            if ($uuid = Uuid::for($url)) {
                                $url = $uuid->model()?->url();
                                $parsedUrl = $url ? $urlParser($url, $kirby) : null;
                                $element->setAttribute($attribute, $parsedUrl);
                            }
                        } catch (InvalidArgumentException) {
                            // Ignore anything else than permalinks
                        }
                    }
                }
            }

            $field->value = $dom->toString();
        }

        return $field;
    },

    /**
     * Enhances the `toBlocks()` method to resolve files and pages
     *
     * @kql-allowed
     */
    'toResolvedBlocks' => function (Field $field) use ($pagesFieldResolver, $filesFieldResolver, $customFieldResolver, $nestedBlocksFieldResolver) {
        return $field
            ->toBlocks()
            ->map($nestedBlocksFieldResolver)
            ->map($pagesFieldResolver)
            ->map($filesFieldResolver)
            ->map($customFieldResolver);
    },

    /**
     * Enhances the `toLayouts()` method to resolve files and pages
     *
     * @kql-allowed
     */
    'toResolvedLayouts' => function (Field $field) use ($filesFieldResolver, $pagesFieldResolver, $customFieldResolver) {
        return $field
            ->toLayouts()
            ->map(function (\Kirby\Cms\Layout $layout) use ($filesFieldResolver, $pagesFieldResolver, $customFieldResolver) {
                foreach ($layout->columns() as $column) {
                    $column
                        ->blocks()
                        ->map($filesFieldResolver)
                        ->map($pagesFieldResolver)
                        ->map($customFieldResolver);
                }

                return $layout;
            });
    }
];
