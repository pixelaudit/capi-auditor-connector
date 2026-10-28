=== CAPI Auditor Connector ===
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later

Sanitized diagnostic log of Meta Conversions API events for the CAPI & Match Quality Auditor Chrome extension.

== Description ==

Conversions API events travel from your server to Meta, so a browser extension cannot see them. This plugin records a sanitized copy of each event your site sends and exposes it, behind a private key, to the Chrome extension. The extension then shows browser and server events side by side: deduplication, match keys, hashing problems and events Meta rejected.

What is stored for each event:

* All event fields: event_name, event_id, event_time, action_source, event_source_url, custom_data, and so on.
* user_data: for each key, whether it is present, hashed and well formed, plus a 10-character hash preview. Raw personal data is never stored; unhashed values are flagged and discarded.
* fbp, fbc and user agent in plain text, since they are needed to match your own browser session. IP addresses are masked (last octet removed).
* Meta's response: HTTP code, events_received, fbtrace_id and error message.
* The plugin that sent the event, which helps find duplicate senders.

Access tokens are never stored. Logs are deleted after 48 hours by default.

== Coverage ==

* Automatic: anything that sends through the WordPress HTTP API (wp_remote_post and similar), for example Facebook for WooCommerce.
* Manual: integrations with their own HTTP client (for example the Facebook PHP Business SDK over curl) can log events by adding one line after they send:

    do_action( 'capi_auditor_record', $request_body, $response, $pixel_id );

* Not visible: Meta's Conversions API Gateway and any server outside WordPress (Stape, server-side GTM). Their events never pass through this site.

== Installation ==

1. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate it.
2. Go to Settings → CAPI Auditor and copy the connection key.
3. In the Chrome extension, open the site, go to CAPI & Match → Server event log, and paste the key.
