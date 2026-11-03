# Hermes Connector

This plugin connects a WordPress site to a Hermes web widget inbox. It adds the
live-chat widget to public pages and identifies logged-in WordPress users.

## Origin

This plugin is derived from
[chatwoot/wp-plugin](https://github.com/chatwoot/wp-plugin) v0.2.2, commit
`ff40757`, licensed GPL-2.0-or-later. This plugin uses the same license.

Differences from the upstream plugin:

- It loads the inbox list from Hermes API v2, so an administrator does not copy tokens by hand.
- It signs the identity of logged-in users on the server.
- It clears the widget session when the WordPress user changes.
- It escapes and sanitizes its settings, and it corrects the `hi` and `zh` locale values.
- It uses `wp_add_inline_script` in place of `wp_localize_script` with string values.
- It selects the widget language from the site locale by default.

## Hermes API

The plugin calls one endpoint from the WordPress server:

```text
POST /api/v2/inbox/web-widget/search
Authorization: Bearer <token>
```

Hermes must include this endpoint. An older installation returns an error
message on the settings page. In that case, enter the website token and the
identity validation token by hand.

## Setup

1. Download `hermes-connector.zip` from the [latest release](https://github.com/Hermes-SRV/hermes-wordpress/releases/latest). Install it with **Plugins > Add New > Upload Plugin** and activate it. Do not use the GitHub "Source code" archive, because its folder name prevents correct updates. The activation copies the settings of the upstream plugin once, if they exist.
2. Deactivate the upstream Chatwoot Plugin. This plugin does not load its widget while the upstream plugin is active.
3. Open **Settings > Hermes Connector**.
4. Enter the Hermes URL and the API token of an account administrator, then select **Connect to Hermes**.
5. Select the inbox, then select **Use this inbox**.

## Updates

WordPress shows new versions of this plugin on the **Plugins** and **Updates** pages. The plugin reads the latest release of this repository. This requires WordPress 5.8 or later.

To install new versions automatically, select **Enable auto-updates** on the plugin row in **Plugins**. This setting is off by default, and each site controls it.

Each site contacts `api.github.com` at most once per hour, and GitHub receives the IP address of the site. The plugin accepts only the `hermes-connector.zip` asset of a release in this repository. It ignores drafts and prereleases.

### Publish a release

1. Set the same new version in the `Version` header and in the `VERSION` constant of `hermes-connector.php`.
2. Merge the change, then push a tag such as `v1.2.3` with the same version.

The release workflow tests the code, builds `hermes-connector.zip` and creates the release. Sites with auto-updates enabled install it without review, so anyone who can push a tag to this repository can run code on those sites. Protect the `v*` tags.

## Security

- The browser never receives the API token or the identity validation token.
- Neither token is displayed again after you save it.
- WordPress stores both tokens as plain text in `wp_options`. After you select the inbox, you can remove the API token. Remove it unless you plan to change the inbox again.
- The API token is sent only to the configured URL. A change to the URL deletes the stored API token and the widget credentials.
- The URL must use HTTPS. HTTP is accepted only for loopback development addresses.

### Email addresses

Hermes merges contacts that have the same email address. The plugin does not
send the email address by default. If you enable this option on a site that does
not verify email addresses, a user can enter the address of another contact and
gain access to the conversations of that contact.

### Page caches

A page for a logged-in user contains that user's signed identity. Do not cache
pages for logged-in users. Most page cache plugins do not cache these pages by
default, but you must verify this before you enable identification.

## Filter

`hermes_connector_identity` changes the identity sent to the widget.
It receives the identity array and the `WP_User`. If you change `identifier`,
recompute `identifier_hash` with the identity validation token.

## Tests

`php tests/test-hermes-connector.php` runs the tests. It requires the PHP CLI.
