# WoodMart Header Builder adapter

## Scope

The Native adapter currently supports one narrowly scoped Header Builder write on the verified WoodMart **8.6.2** API:

- Read a header layout and its snapshot/context hashes.
- Plan insertion of a native `mainmenu` element into the inspected empty desktop slot `default_header / general-header / column9`.
- Select an existing WordPress navigation menu by ID.
- Save through WoodMart's native `Header_Factory::update_header()` and `Header::save()` pipeline.
- Verify the saved structure and restore the exact prior header through the normal sandbox rollback path.

It does not expose arbitrary serialized header-option writes, row/column rearrangement, arbitrary element insertion, custom CSS/JS, or unverified WoodMart versions. Unknown element schemas and non-empty target columns fail closed.

## MCP tools

- `woodmart.header.status` — read adapter/theme compatibility.
- `woodmart.header.get` — read a header structure and snapshot hash.
- `woodmart.header.add_main_menu_sandbox` — create a pending operation to insert the Mainmenu element.
- `woodmart.mega_menu.get` — read the whitelisted mega-menu metadata for a menu item.
- `woodmart.mega_menu.sandbox` — create a pending operation assigning a published HTML Block to a menu item.

The write tools create sandbox operations only. Live changes still require the normal administrator approval, Apply, verification, receipt and rollback lifecycle.

## Mega-menu metadata

Only these WoodMart menu item fields are supported:

- `_menu_item_design`
- `_menu_item_block`

Supported design values: `default`, `full-width`, `full-height`, `sized`, `aside`.

A non-default design requires a published `cms_block` HTML Block. Draft, private, trashed, missing or wrong-post-type blocks are rejected. Width, height, padding, AJAX and other menu metadata are intentionally not writable in this first version.

## Safety and compatibility

- Header Builder writes are enabled only when the installed theme version is exactly `8.6.2` and the native Header Builder factory/Mainmenu schema is loaded.
- Mega-menu writes are pinned to the same theme version.
- Header writes carry before/after snapshots, theme context, row/column/menu identity and a snapshot fingerprint.
- Apply rejects a stale header, a changed theme version, an occupied target column, any changed header settings, or any diff beyond the one approved Mainmenu insertion.
- A per-header lock prevents concurrent adapter writes.
- Mega-menu writes bind the menu item to its existing menu, use a strict metadata whitelist, verify the published HTML Block, and snapshot whether each meta key existed so rollback can restore absence.
- The existing mobile menu location and burger element are not changed by the desktop Mainmenu insertion.

## Site rollout notes

For wpaiadmin.ru, the live read-only audit found the default header's desktop `general-header / column9` empty, with the logo in `column8` and account/cart in `column10`. The current WordPress menu ID 20 is assigned to both desktop and mobile locations. The desktop Mainmenu can select that menu directly while the mobile burger remains unchanged.

Before creating or applying the final menu operations, verify that every destination page is published and that the assigned HTML Block is published. Never expose draft page links in the public header.
