# Free 1.1.0 extension API

The actions, filters, JavaScript registry and scope-validator registration documented
here are public extension APIs starting with 1.1.0. Other classes/methods are internal.
Register PHP listeners during plugin file load, before Free boots at `plugins_loaded`
priority 20. Examples use WordPress listener priority 10 unless stated otherwise.
Do not call the resolver recursively, write customer data from a price filter, or
install a second wholesale pricing engine. Management routes still require normal
WordPress authentication and `manage_woocommerce`; hooks do not grant permissions.

## Price and scope filters

`Context` is `['uid'=>int, 'roles'=>string[], 'groups'=>int[]]`. Free restores trusted
uid/roles and normalizes groups to unique sorted positive ids. Guests have uid 0.
`Rule` is an array with `id`, `product_id`, `variation_id`, `scope_type`,
`scope_value`, `price_type`, `price_value`, `sale_price`, `min_qty`, `date_from`,
`date_to`. Use `fixed`, `percent_discount` or `fixed_discount`; numeric values must
be finite/nonnegative, percentages at most 100 and min_qty at least 1. Sale cap
0/null means no cap. Stored Free date fields remain ignored on save; contributed
rules with dates use inclusive site-local YYYY-MM-DD boundaries.

| Filter | Arguments in order | Timing and validation |
|---|---|---|
| `cewp_user_context` | Context, int user_id | Before candidate lookup, once per context cache miss. Only groups can enrich trusted identity. Return Context. |
| `cewp_price_rules` | Rule[], float raw_base, int product_id, int variation_id, int quantity, Context | Before selection, once per resolution cache miss. Return candidates; invalid/foreign-product/ineligible rules are discarded. User before group before role; exact variation before parent; highest qualifying quantity then lowest id. |
| `cewp_resolve_price` | Fallback, float raw_base, int product_id, int variation_id, int quantity, Context, bool no_rule_matched | Only on product-rule miss. Return `['matched'=>bool,'rule'=>?Rule,'source'=>?string]`. First valid match wins, even at the retail price. Match requires a valid rule and nonempty source; unmatched is exactly false/null/null. Category convention priority 10, global 20. |
| `cewp_resolved_price` | float price, int product_id, int variation_id, int quantity, Context | Only for matched dealer rules/fallbacks, after single rule application, guest-price ceiling and rounding. Return finite nonnegative numeric price. Conversion is cached and not rounded again by Free. Never called for guests/unmatched dealers. |
| `cewp_allowed_scope_types` | string[] types | During rule CRUD/query validation; defaults to `['role']`. Only role/user/group accepted. Enabling a type does not validate its identities. Disabled saved rows persist but do not match. |

Free applies the selected rule once to the raw regular price, caps it to the
guest-visible active retail price, and rounds before `cewp_resolved_price`.
Repeated identical resolutions reuse request caches. Invalid filter returns retain
the previous valid value; listeners must return the full documented structure.

```php
add_filter('cewp_user_context', function (array $ctx, int $uid): array {
    $ctx['groups'] = example_group_ids($uid); // Your server-side membership lookup.
    return $ctx;
}, 10, 2);

add_filter('cewp_price_rules', function (array $rules, float $base, int $pid,
    int $vid, int $qty, array $ctx): array {
    return $rules; // Append valid product candidates here, never final prices.
}, 10, 6);

add_filter('cewp_resolve_price', function (array $result, float $base, int $pid,
    int $vid, int $qty, array $ctx, bool $unmatched): array {
    if ($result['matched'] || !$unmatched) return $result;
    $rule = example_category_rule($pid, $vid, $qty, $ctx); // Your eligibility lookup.
    return $rule === null ? $result
        : ['matched'=>true, 'rule'=>$rule, 'source'=>'example/category'];
}, 10, 7);

add_filter('cewp_resolved_price', function (float $price, int $pid, int $vid,
    int $qty, array $ctx): float {
    return round($price * example_exchange_rate(), wc_get_price_decimals());
}, 10, 5);

add_filter('cewp_allowed_scope_types', function (array $types): array {
    $types[] = 'group';
    return array_values(array_unique($types));
});
add_action('cewp_loaded', function (string $version): void {
    if (version_compare($version, '1.1.0', '>=')) {
        \CEWP\Scopes::register_validator('group', function (string $id): bool {
            return example_group_exists((int) $id);
        });
    }
});
```

`Scopes::register_validator(string $type, callable $fn): void` is the public PHP
registration API for user/group validators. It receives a positive decimal id
string and must return exactly true for an existing identity. User defaults to
WordPress user existence; group fails closed without a validator. REST and CSV use
these same scope checks. Filters never authorize writes or enable a management UI.

## Lifecycle, REST, settings and roles

| Hook | Arguments / return | Timing and validation |
|---|---|---|
| `cewp_loaded` action | string free_version / void | After Free services boot, at plugins_loaded priority 20, once per request with WooCommerce available. Check dependencies/version; keep add-on boot idempotent. |
| `cewp_install` action | string old_version, string new_version / void | After successful Free schema upgrade only. Does not replace an add-on's independent installer/version option. |
| `cewp_register_rest_routes` action | string namespace, callable admin_permission / void | During rest_api_init after core routes. Namespace `cewp/v1`; unique paths only, core routes cannot be overwritten. Public endpoints must supply their own appropriate permission callback. |
| `cewp_admin_tabs` filter | Tab[], Context / Tab[] | Authorized Free screen bootstrap. Core ids cannot be replaced. Namespaced unique id, nonempty label, integer order, allowed capability, valid script handle required. Sort by order then id. Labels become plain text and render escaped. |
| `cewp_settings_schema` filter | array<string,Setting> / same | Cached per listener configuration/request. Core keys cannot be redefined. Extension keys must start with owner + underscore (hyphens normalized). Secret/license/token/password/credential names rejected. |
| `cewp_is_dealer` filter | bool is_dealer, Context / bool | After stored-role lookup, once per context cache miss. Requires a boolean. |
| `cewp_dealer_role` filter | ?string role_key, Context / ?string | After configured dealer-role selection, once per context cache miss. Only an existing configured dealer role or null. |

`Tab = ['id'=>string,'label'=>string,'order'=>int,'capability'=>string,
'script_handle'=>string]`. `Setting = ['type'=>string,'default'=>mixed,
'sanitize_callback'=>callable,'owner'=>string]`, with optional `validate_callback`.
Types: boolean/integer/number/string/array. Arrays require a validation callback.
Defaults must be valid. Partial settings writes preserve unrelated stored keys;
unknown/invalid writes fail atomically with HTTP 400. Never store secrets in this schema.
Role caches invalidate on Free role writes and WordPress membership/role-option changes.

```php
add_action('cewp_loaded', function (string $version): void {
    if (version_compare($version, '1.1.0', '>=')) example_boot_once();
});
add_action('cewp_install', function (string $old, string $new): void {
    example_refresh_integration_metadata($old, $new);
}, 10, 2);
add_action('cewp_register_rest_routes', function (string $ns, callable $auth): void {
    register_rest_route($ns, '/example/groups', [
        'methods'=>'GET', 'callback'=>'example_list_groups', 'permission_callback'=>$auth,
    ]);
}, 10, 2);
add_filter('cewp_admin_tabs', function (array $tabs, array $ctx): array {
    $tabs[] = ['id'=>'example/groups','label'=>__('Groups', 'example-addon'),
        'order'=>50,'capability'=>'manage_woocommerce','script_handle'=>'example-admin'];
    return $tabs; // Register example-admin with WordPress before Free enqueues it.
}, 10, 2);
add_filter('cewp_settings_schema', function (array $schema): array {
    $schema['example_enabled'] = ['type'=>'boolean','default'=>false,
        'sanitize_callback'=>'rest_sanitize_boolean','owner'=>'example'];
    return $schema;
});
add_filter('cewp_is_dealer', function (bool $dealer, array $ctx): bool {
    return $dealer; // Can add verified server-side eligibility.
}, 10, 2);
add_filter('cewp_dealer_role', function (?string $role, array $ctx): ?string {
    return $role; // Select an existing configured dealer role, or null.
}, 10, 2);
```

## JavaScript admin renderer

`window.CEWP.registerTab(id, render)` registers a public renderer for a
server-approved tab id. Register after Free's script and before DOMContentLoaded
mount (declare `cewp-admin` as a script dependency). Duplicate/core ids are rejected;
an absent renderer shows an unavailable state. Renderers receive the app container; bootstrap data is available on window.CEWP.
They may return an HTML string (escape dynamic content) or render into the container. UI visibility never substitutes for REST authorization.

```js
window.CEWP.registerTab('example/groups', (container) => {
    const heading = document.createElement('h2');
    heading.textContent = 'Groups'; // Localize through your own script data.
    container.appendChild(heading);
});
```

The example_* functions above belong to the add-on and are illustrative placeholders.
Free owns shared product-rule CRUD and settings. Add-ons own their tables, installer
version, permissions and removal policy; they must preserve shared Free data.

## Dealer product visibility

`cewp_visible_product_ids(array $ids, array $context): array` adjusts the role-rule
product allow-list when `hide_retail_from_wholesale` is enabled and the current
user is a dealer. Both the main product catalog query and single-product redirect
use this filter. Context is the trusted `cewp_user_context` structure described
above; add-ons may use its groups for their own server-side rule lookup.

Return the complete replacement list, or append to `$ids` to retain role products.
Every returned ID must be a positive PHP integer identifying an existing `product`
post (use the parent product ID for variations). Numeric strings, floats, booleans,
zero, negative, nonexistent and other post-type IDs are rejected. An invalid
listener return retains the previous valid list before the next listener runs.
Duplicates are removed; an empty array deliberately hides all products. No listener
preserves Free role behavior. Nondealers and disabled settings never dispatch it.
This hook controls these two visibility surfaces only; it does not grant purchase,
REST or administration permissions. Results are not cached by Free.

```php
add_filter('cewp_visible_product_ids', function (array $ids, array $ctx): array {
    return array_merge($ids, example_group_product_ids($ctx['groups']));
}, 10, 2);
```

## Public API: price tiers

```php
$tiers = function_exists('cewp_get_dealer_price_tiers')
    ? cewp_get_dealer_price_tiers($product_id, $variation_id, $user_id)
    : [];
// Example: [['min_qty'=>1, 'price'=>90.0], ['min_qty'=>5, 'price'=>75.0]]
```

Supported global read function:
`cewp_get_dealer_price_tiers(int $product_id, int $variation_id = 0, int $user_id = 0): array`.
Add-ons guard calls with `function_exists`. Pass a parent/simple product ID,
variation ID (default 0 for the product), and user ID (0 for the current user). Invalid,
nonexistent or mismatched product/variation IDs, nondealers and unmatched products
return `[]`. An explicit user lookup preserves the caller's current user and
resolver cache, including when a listener throws. It does not alter cart products.
This server-side API does not grant REST or administration permissions; callers
must authorize any exposure of another user's prices themselves.

Rows contain only `min_qty` (positive integer) and `price` (finite nonnegative
float from Free). Thresholds are distinct and ascending. Quantity 1 is always
included when a rule matches; if the first dealer tier starts later, quantity 1
has the native unit price. Matching uses the resolver's scope/date/variation
precedence, so other roles and fully shadowed rules contribute no thresholds.
The threshold inventory comes from applicable product candidates (including
`cewp_price_rules` evaluated at the maximum integer quantity). Add-ons providing
quantity-dependent fallback rules must supply applicable product candidates
through `cewp_price_rules` to contribute thresholds. Each returned price uses the single resolver at that quantity,
including the guest ceiling, D14 zero-cap handling, rounding and
`cewp_resolved_price` conversion. Fixed zero remains zero. No presentation HTML,
rule objects or identifiers are returned.

Free's implementation classes remain internal.
