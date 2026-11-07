=== CoderEmbassy Wholesale Pricing for WooCommerce ===
Contributors: codersaleh, phpcoderhannan
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: wholesale, b2b, pricing, woocommerce

A powerful wholesale pricing plugin for WooCommerce, allowing role-based pricing and discounts.

== Description ==

Coderembassy wholesale pricing allows you to seamlessly add a B2B or wholesale facet to your existing WooCommerce store. You can create custom pricing rules based on user roles to offer specialized rates to different tiers of customers.

https://www.youtube.com/watch?v=T0McHW0JF2g

Features:
* Automatically creates a "Wholesale Customer" role.
* Apply fixed prices, percentage discounts, or fixed amount discounts.
* Set minimum order quantities for wholesale pricing.
* Hide retail products or prices from wholesale buyers and vice-versa.
* Catalog mode to hide prices and add-to-cart buttons for guests.
* Import pricing rules via CSV.

== Developers ==

Free 1.1.0 provides cewp_user_context, cewp_price_rules, cewp_resolve_price, cewp_resolved_price, cewp_allowed_scope_types, cewp_loaded, cewp_install, cewp_register_rest_routes, cewp_admin_tabs, cewp_settings_schema, cewp_is_dealer and cewp_dealer_role. JavaScript extensions can register admin renderers with window.CEWP.registerTab. See HOOKS.md in the source repository for signatures, validation and examples.

Additional functionality can be added through extensions that use these hooks.

== Installation ==

1. Ensure WooCommerce is installed and active.
2. Upload the plugin folder to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' screen in WordPress.
4. Navigate to WooCommerce > CoderEmbassy Wholesale Pricing to configure your settings and start adding pricing rules.

== Frequently Asked Questions ==

= Does this support product variations? =
Yes, you can apply pricing rules to specific variations or globally to the parent variable product.

= Can I import my prices? =
Yes, a built-in CSV importer is available to bulk-load pricing rules.

= Can I Hide Retail prices? =
Yes, You can hide retail price.




== Screenshots ==

1. Dashboard.
2. Pricing Role
3. Add Pricing Role
4. Pricing Role table
5. CSV Import
6. settings
7. Dark Theme

== Changelog ==

= 1.1.0 =
* Fix: dealer prices were discounted more than once in the cart and on repeated cart updates. Rules now apply once to the regular price.
* Fix: a dealer is never charged more than the price a guest sees (sale items stay at the sale price when the rule would be higher).
* Fix: a rule sale price of 0 no longer sells the product for free; 0 now means "no sale cap". A fixed rule price of 0 still works.
* Fix: quantity tiers now pick the highest tier the quantity qualifies for, in the catalog and in the cart.
* Fix: the "disallow coupons for dealers" setting is now honoured (dealer coupons stay blocked by default).
* Fix: "Delete data on uninstall" now uses the setting shown in the admin, and never deletes shared data while a Wholesale Pricing add-on is installed.
* Fix: CSV import no longer stops at the first duplicate row; it reports each failing row with its line number and ignores missing optional columns without warnings.
* Fix: the price API rejects missing or invalid product, price type or price value with a clear error instead of saving empty values.
* Fix: saving an unchanged rule works, real save errors and duplicate rules are shown in the admin, and the edit window shows the product name.
* Developer: new actions and filters for extensions (see the Developers section and HOOKS.md in the source repository).
* Developer: malformed stored discount rules (including percentage discounts above 100) are ignored.

= 1.0.0 =
* Initial release.
