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

* A chat bubble in the bottom right or bottom left corner, in your accent color, with your team name and avatar.
* Your working hours. Outside them the chat shows your away message instead.
* An email fallback. If nobody is online or the visitor leaves the page, they can leave an email address and your answer is sent there.
* File attachments: images (JPEG, PNG, GIF, WebP) and PDF files.
* German and English. German is included for Germany, Austria and Switzerland, in the informal "du" and the formal "Sie" form. The language follows your site's language setting.

**What you control**

* The FreeScout address, site ID and secret.
* Team name, avatar, accent color, position and welcome messages.
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

Almost never. The chat stores nothing in the browser until a visitor sends a message. The only cookie is a short-lived one set when a logged-in visitor logs out of your site. See the Privacy section for what the plugin stores and sends.

= What happens outside working hours? =

The chat shows your away message and asks for an email address, so your team can answer by email. Visitors can still send a message. Without any working hours, the chat does not use a schedule.

= Can I hide the bubble on some pages? =

Yes. In Settings → Live Chat for FreeScout, list the pages under "Hide on pages", one path per line, for example `/checkout/`. A line that ends in `*` hides the chat on every page below that path.

== Changelog ==

= 0.1.0 =
* Initial version.
