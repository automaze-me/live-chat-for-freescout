=== Live Chat for FreeScout ===
Contributors: automazeme
Tags: live chat, chat, helpdesk, freescout, support
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a live chat bubble to your site. Visitor messages arrive in your FreeScout help desk.

== Description ==

Live Chat for FreeScout puts a chat bubble on your website. Visitors type a message, and it lands in your own [FreeScout](https://freescout.net) help desk as a normal conversation. Your team answers there, and the reply appears in the visitor's chat.

FreeScout is a free, self-hosted help desk. This plugin does not work on its own: it needs a FreeScout installation with the Website Chat module, which is available at [github.com/automaze-me/freescout-wordpresschat](https://github.com/automaze-me/freescout-wordpresschat). Messages go straight from the visitor's browser to your FreeScout. They never pass through your WordPress site or any third-party chat service.

**What your visitors see**

* A chat bubble in the bottom right or bottom left corner, in your accent color or a button color of its own, with your team name and avatar.
* Your working hours. Outside them the chat shows your away message instead.
* An email fallback. If nobody is online or the visitor leaves the page, they can leave an email address and your answer is sent there.
* File attachments: images (JPEG, PNG, GIF, WebP) and PDF files.
* German and English. German is included for Germany, Austria and Switzerland, in the informal "du" and the formal "Sie" form. The language follows your site's language setting.

**What you control**

* The FreeScout address, site ID and secret.
* Team name, avatar, accent color, button color, position and welcome messages. A white ring around the button keeps it visible on a background of the same color.
* Working hours for each day of the week.
* Pages where the bubble stays hidden, such as the checkout.
* Your privacy policy link, shown inside the chat.

If you use WooCommerce, logged-in customers are recognized and visitors can enter an order number, so your team sees who they are talking to.

== Installation ==

**In FreeScout**

1. Make sure the Website Chat module is installed and active.
2. Open Manage → Website Chat and add your website.
3. Copy the Site ID and the secret that FreeScout shows for it.

**In WordPress**

1. Install and activate the plugin: upload it to `/wp-content/plugins/live-chat-for-freescout`, or install it from the Plugins screen.
2. Open Settings → Live Chat for FreeScout.
3. Enter the FreeScout URL, the Site ID and the secret.
4. Choose how the chat looks and when you are available.
5. Click "Test connection" to check that WordPress can reach FreeScout and that the details match, then save.

The bubble appears on your site as soon as the FreeScout URL and the Site ID are saved.

== Frequently Asked Questions ==

= Do I need FreeScout? =

Yes. The plugin is the chat window for your site. Conversations are stored and answered in your own FreeScout help desk with the Website Chat module installed. Without it, there is nobody to receive the messages.

= Does it work with page caching? =

Yes. The chat loads in the visitor's browser and talks to FreeScout directly, so cached pages work like any other page. Page caches and optimization plugins that combine or delay scripts normally need no special setting.

= Does it set cookies? =

Almost never. The chat stores nothing in the browser until a visitor uses it: sends a message, continues a chat from an email link, or, when logged in with an existing conversation, opens the chat. The only cookie is a short-lived one set when a logged-in visitor logs out of your site. See the Privacy section for what the plugin stores and sends.

= What happens outside working hours? =

The chat shows your away message. After the visitor's first message it asks an anonymous (not logged-in) visitor for an email address, so your reply can reach them, and the visitor answers or dismisses that before writing more. Without any working hours, the chat is treated as always open.

= Can I hide the bubble on some pages? =

Yes. In Settings → Live Chat for FreeScout, list the pages under "Hide on pages", one path per line, for example `/checkout/`. A line that ends in `*` hides the chat on every page below that path.

== External services ==

This plugin connects your website to a FreeScout help desk. FreeScout is free, self-hosted help desk software ([freescout.net](https://freescout.net)). You run the FreeScout server yourself, or someone you trust runs it for you, and you enter its address in Settings → Live Chat for FreeScout. The chat does not work without it, and no other service is involved: no chat provider, no analytics, no tracking.

**Calls from the visitor's browser to your FreeScout server**

When a visitor uses the chat, their browser talks directly to the FreeScout address you configured. The Website Chat module on that server ([github.com/automaze-me/freescout-wordpresschat](https://github.com/automaze-me/freescout-wordpresschat)) receives:

* The messages the visitor writes and the files they attach.
* An email address or order number, if the visitor enters one in the chat.
* The path of the page the visitor is viewing (without query string or fragment), while a conversation is open, so your team sees where the visitor is.
* A random chat token that identifies the conversation in the browser.

Showing the bubble to a new visitor sends nothing to FreeScout, and a conversation is created only when the visitor sends a message. A returning visitor with a stored chat token resumes the chat as the page loads. When a logged-in visitor opens the chat, the browser asks FreeScout whether the account already has conversations, which sends the signed account details (user ID, name and email address). Once a visitor has a conversation, the browser also talks to FreeScout to fetch replies, mark replies as read, download attachments, continue the chat from an email link and recognize a logged-in account. After a WordPress logout, the chat tells FreeScout to close that browser's chat, even before the panel is opened.

Like any web server, your FreeScout server receives the visitor's IP address and browser user agent with each of these requests.

**Calls from your WordPress server to your FreeScout server**

* The "Test connection" button in the settings sends your Site ID and, once a secret is saved, a short-lived signed check that includes your website's address to FreeScout. It runs only when an administrator clicks it. The plugin makes no other request from your server to FreeScout.

**Logged-in visitors and orders**

If a secret is saved in the settings, your WordPress server signs the identity of a logged-in visitor, and the browser passes it to FreeScout when the visitor opens the chat: the WordPress user ID, display name and email address. If WooCommerce is active and a visitor enters an order number, your server looks the order up and the browser passes the order number, the order's email address and the customer's name to FreeScout, so your team sees which order the visitor means.

**Who is behind it**

FreeScout is software that you or your host operates, so its terms and privacy practices are yours. The plugin and the Website Chat module are by automaze.me. FreeScout's own project site is [freescout.net](https://freescout.net).

== Privacy ==

**What the plugin stores in the browser**

Nothing is stored until a visitor uses the chat: sends a message, continues a conversation from an email link, or, while logged in, opens the chat when the account already has conversations. Showing the bubble to a new visitor stores nothing. After that the chat stores:

* `localStorage` `lcff_token`: the random chat token that continues the conversation on other pages.
* `localStorage` `lcff_seen`: the number of the last reply the visitor has seen.
* `localStorage` `lcff_ask`: whether the chat has already asked for an email address.
* `localStorage` `lcff_acct`: which account the conversation belongs to, so it does not carry over to another person.
* `sessionStorage` `lcff_page`: a per-tab setting for the page address feature, deleted when the tab closes.
* Cookie `lcff_logout` (10 minutes): set by your WordPress site when a user logs out, so the chat closes the conversation in that browser. It is not tracking, and it is not set for anyone else.

On a multisite network, every site but the main site adds `_` and its site ID to the storage key names (the `lcff_logout` cookie keeps its name), so sites that share a domain keep separate chats.

The chat registers itself with the WP Consent API as functional, so consent plugins can list the cookie. Because nothing is stored before a visitor chooses to use the chat, the plugin does not show a consent banner of its own.

**What WordPress stores**

The plugin stores no visitor data in WordPress. Its settings are one option (`lcff_settings`). To limit abuse of the identity and order-number checks, it keeps short-lived transients that hold keyed hashes of IP addresses, not the addresses. They expire on their own. Uninstalling the plugin removes the option and the transients.

**Your privacy policy**

Under Settings → Privacy → Policy Guide, the plugin adds suggested text that describes the data above. Add how long your help desk keeps chat conversations. You can also show a link to your privacy policy inside the chat, in the plugin settings.

== Changelog ==

= 0.1.0 =
* Initial version.
