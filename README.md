# GX Zoho Bookings

Connect [Zoho Bookings](https://www.zoho.com/bookings/) to WordPress. By **Genex Marketing Agency Ltd**.

Two integration modes:

- **Embed mode** — paste your Zoho Bookings booking page URL and display it anywhere with a shortcode or block. Works on every Zoho plan, zero API setup.
- **API mode** — connect via OAuth2 and display your services (name, duration, description, price, book link) fetched live from the Zoho Bookings API, with caching.

Built to work within the **Zoho Bookings Free plan** — no payments, team booking, CRM sync, or other paid-plan features are required (extension points for those are marked with `// FUTURE (paid plan):` comments in the code).

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Zoho Bookings account (Free plan is fine)

## Installation

1. Copy the `gx-zoho-bookings` folder into `/wp-content/plugins/` (or upload a zip of it via Plugins → Add New → Upload).
2. Activate **GX Zoho Bookings** in Plugins.
3. Go to **Settings → Zoho Bookings**.

## Embed mode setup (fastest)

1. In Zoho Bookings, open your booking page and copy its public URL (looks like `https://yourbusiness.zohobookings.com/...` — regional domains like `.eu`, `.in` also work).
2. In **Settings → Zoho Bookings**, set **Mode** to *Embed* and paste the URL into **Booking Page URL**. Save.
3. Add the shortcode or block to any page:

```
[zoho_bookings_embed]
[zoho_bookings_embed url="https://yourbusiness.zohobookings.com/#/yourservice" height="900"]
```

| Attribute | Default | Notes |
|-----------|---------|-------|
| `url` | settings value | Must be a Zoho Bookings domain; anything else is rejected |
| `height` | `750` | iframe height in px |

## API mode setup

1. Complete the **Zoho API Console checklist** below to get a Client ID + Secret.
2. In **Settings → Zoho Bookings**: set **Mode** to *API*, pick your **Data Center Region** (must match where your Zoho account is hosted), enter **Client ID** and **Client Secret**, save.
3. Open the **Connection** tab and click **Connect to Zoho**. Approve the consent screen.
4. Add the services shortcode or block:

```
[zoho_bookings_services]
[zoho_bookings_services workspace="1234567890" columns="2" show_description="no" show_duration="yes"]
```

| Attribute | Default | Notes |
|-----------|---------|-------|
| `workspace` | settings value / first workspace | Zoho workspace ID |
| `columns` | `3` | 1–4; collapses to 1 column under 768px |
| `show_description` | `yes` | `yes`/`no` |
| `show_duration` | `yes` | `yes`/`no` |

Both shortcodes are also available as blocks: **Zoho Bookings Embed** and **Zoho Bookings Services**, with live previews and sidebar controls.

## Zoho API Console checklist

1. Sign in at [api-console.zoho.com](https://api-console.zoho.com) **using the same data center as your Zoho Bookings account** (e.g. api-console.zoho.eu for EU accounts).
2. Click **Add Client** → choose **Server-based Applications**.
3. **Client Name**: anything (e.g. "My WordPress Site").
4. **Homepage URL**: your site URL, e.g. `https://example.com`.
5. **Authorized Redirect URIs**: copy the exact **Redirect URI** shown on the plugin settings page (it looks like `https://example.com/wp-admin/options-general.php?page=gx-zoho-bookings&gx_zb_oauth=callback`).
6. Create, then copy the **Client ID** and **Client Secret** into the plugin settings.
7. In the plugin, make sure the **Data Center Region** matches the console you used.

Scopes requested by the plugin: `zohobookings.data.CREATE`, `zohobookings.data.READ`.

## Managing bookings inside WordPress (v1.1)

A dedicated **Zoho Bookings** top-level admin menu gives you full appointment management without leaving wp-admin. Requires **API mode** with an active OAuth connection.

- **Dashboard** — stat cards for today's appointments, the next 7 days, and completions in the last 30 days, plus a quick list of today's upcoming appointments with one-click Complete.
- **Appointments** — sortable, paginated list of all appointments. Filter by status (Upcoming / Completed / Cancelled / No-show) and date range. Row actions: Mark Completed, Cancel, No-show (each nonce-protected with a confirm dialog) and Reschedule.
- **New Booking** — create a booking on behalf of a customer: pick a service, staff member and date, choose from live availability slots fetched from Zoho, enter customer name/email/phone and optional notes. Times use your WordPress timezone (`Settings → General`).
- **Reschedule** — from any upcoming appointment's row action; pick a new date and slot.
- **Admin bar** — a "Bookings" quick menu with today's appointment count badge, visible to administrators on both front end and admin.

All write operations (create, reschedule, status change) invalidate the plugin's API cache automatically.

## Connecting an AI booking agent (MCP) (v1.2)

The plugin exposes a Model Context Protocol (MCP) server over HTTP, allowing AI agents to query services and manage bookings. Enable the MCP endpoint and authenticate with an API key to give your AI booking agent controlled access.

**Voice agents**: for platform-specific setup — Vapi, ElevenLabs Agents, Bland, Dograh, and xAI Grok Voice — see [VOICE-AGENTS.md](VOICE-AGENTS.md).

### Enable the MCP endpoint

1. Go to **Settings → Zoho Bookings → AI Agent Access (MCP)**.
2. Check **Enable MCP endpoint**.
3. Click **Generate API Key** (or **Regenerate** if a key already exists).
4. Copy the **Endpoint URL** and **API Key** shown on the settings page.
5. Configure your AI agent using one of the examples below.

### Claude Code

```bash
claude mcp add zoho-bookings --transport http https://example.com/wp-json/gx-zb/v1/mcp --header "Authorization: Bearer YOUR_KEY"
```

### Claude Desktop (via mcp-remote)

```json
{
  "command": "npx",
  "args": ["-y", "mcp-remote", "https://example.com/wp-json/gx-zb/v1/mcp", "--header", "Authorization: Bearer YOUR_KEY"]
}
```

### Generic curl handshake

1. **Initialize**  
   ```bash
   curl -X POST https://example.com/wp-json/gx-zb/v1/mcp \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -d '{
       "jsonrpc": "2.0",
       "id": 1,
       "method": "initialize",
       "params": {
         "protocolVersion": "2025-06-18",
         "capabilities": {},
         "clientInfo": {"name": "my-agent", "version": "1.0"}
       }
     }'
   ```

2. **List tools**  
   ```bash
   curl -X POST https://example.com/wp-json/gx-zb/v1/mcp \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -d '{
       "jsonrpc": "2.0",
       "id": 2,
       "method": "tools/list"
     }'
   ```

3. **Call `get_connection_status`**  
   ```bash
   curl -X POST https://example.com/wp-json/gx-zb/v1/mcp \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -d '{
       "jsonrpc": "2.0",
       "id": 3,
       "method": "tools/call",
       "params": {
         "name": "get_connection_status",
         "arguments": {}
       }
     }'
   ```

### Tool catalog

| Tool | What it does | Required arguments |
|------|--------------|-------------------|
| `get_connection_status` | Returns current connection status (always works, even when disconnected). | None |
| `list_workspaces` | Lists all workspaces available in Zoho Bookings. | None |
| `list_services` | Lists all services within a workspace. | None (`workspace_id` optional) |
| `list_staff` | Lists staff members for a given service. | `service_id` |
| `get_available_slots` | Returns available time slots for a service, staff, and date (date must be YYYY-MM-DD). | `service_id`, `staff_id`, `date` |
| `list_appointments` | Lists appointments filtered by status and date range (dates in YYYY-MM-DD). | None (all optional but recommended) |
| `get_appointment` | Retrieves details of a single appointment by its booking ID. | `booking_id` |
| `create_booking` | Creates a new appointment. Requires service, staff, date (YYYY-MM-DD), time (from `get_available_slots`), customer name and email. | `service_id`, `staff_id`, `date`, `time`, `customer_name`, `customer_email` |
| `reschedule_booking` | Changes the date/time (and optionally staff) of an existing booking. | `booking_id`, `date`, `time` |
| `update_booking_status` | Updates the status of a booking to `completed`, `cancel`, or `noshow`. | `booking_id`, `action` |

### Security notes

- **HTTPS is required** – the API key is transmitted with every request; never use plain HTTP.
- **The API key grants full booking control** – treat it like a password. Anyone with the key can create, modify, or cancel bookings.
- **Regenerate the key to revoke access** – use the Regenerate button on the settings page; the old key stops working immediately.
- **Disable the toggle to kill the endpoint** – unchecking "Enable MCP endpoint" rejects all requests, regardless of the API key.

## Setting up services and staff from WordPress (v1.3)

## Services page walkthrough

Navigate to **Zoho Bookings → Services** to manage your booking services.

- **List view**: Select a workspace from the dropdown to filter services. Use the **Add New Service** button to create a new service. The table shows: Name, Duration, Cost, Mode (Online/Offline), Status (Active/Inactive badge), Assigned staff count, and Actions (Edit, Activate/Deactivate, Delete with confirmation).
- **Create / Edit**: Fill in the form card – name (required), duration (minutes, 5-step), cost, description, pre/post buffers, meeting mode/type (online requires meeting type), and assigned staff checkboxes. When editing, you can also change status.
- **Delete**: Confirm via dialog; action is irreversible.
- **Workspace creation**: At the bottom of the list view, a collapsed inline form lets you create a new workspace (name 2‑50 chars, no special chars `|/\,?{}<>:;"'`).

**Note**: Only one‑on‑one services are supported (Zoho constraint). Resource/group booking fields have reserved placeholders for future paid plans.

## Staff page

Navigate to **Zoho Bookings → Staff**. This page shows a list of existing staff (Name, Email, Designation, Role) and an **Add Staff** form.

- **Important**: Zoho’s API does **not** provide endpoints to edit or delete staff. To modify existing staff, log into [Zoho Bookings admin](https://bookings.zoho.com). A notice on the page reminds you of this limitation.
- **Add Staff**: Provide name (required), email (required, validated), phone, designation, role (Staff/Manager/Admin), and assign services (checkboxes). The form submits via `admin_post_gx_zb_staff_add`. On free plans, only 1 staff member is allowed – API errors will surface in an admin notice.

## MCP setup tools

The following tools are available in the MCP server for automated setup (total 15 tools now):

| Tool | Required Parameters | Optional Parameters | Notes |
|------|--------------------|---------------------|-------|
| `create_workspace` | `name` | – | Name 2‑50 chars, no `|/\,?{}<>:;"'` |
| `create_service` | `name`, `workspace_id` | `duration`, `cost`, `description`, `pre_buffer`, `post_buffer`, `meeting_mode`, `meeting_type`, `assigned_staff_ids` (array) | One‑on‑one only; meeting_type required when mode is `online` |
| `update_service` | `service_id` | `name`, `duration`, `cost`, `description`, `pre_buffer`, `post_buffer`, `meeting_mode`, `meeting_type`, `assigned_staff_ids` (array), `status` (`active`/`in_active`) | Partial updates allowed |
| `delete_service` | `service_id` | – | **Destructive and irreversible** – confirm with user first |
| `add_staff` | `name`, `email` | `phone`, `designation`, `role` (`Staff`/`Manager`/`Admin`), `assigned_service_ids` (array) | No edit/delete via API – manage existing staff in Zoho admin |

## Free‑plan limits

- **1 workspace** (typical for free tier)
- **1 staff member** (can be added, but if the plan allows only one, API will reject additional)
- **250 API calls per day** (shared across all Zoho Bookings API operations)
- Free tier does **not** support resource‑based or group services.

## Caching

Successful API responses are cached in transients (default 15 minutes, configurable 1–1440). Use the **Flush API Cache** button on the settings page after changing services in Zoho.

## Free plan limitations

The Zoho Bookings Free plan typically allows one workspace, one staff member and basic appointment features, which the plugin fully supports. **Paid-plan features** — resource booking, group/collective booking, custom booking fields, multiple workspaces, extra staff and Zoho CRM sync — are supported as of v2.0.0 but require a paid Zoho Bookings plan (and, for CRM, a Zoho CRM subscription). See **Paid-plan features** below.

## FAQ

**"Your Zoho Bookings connection has expired" notice** — your refresh token was revoked (e.g. re-generated in the API console) or is invalid. Open Settings → Zoho Bookings → Connection and click **Connect to Zoho** again.

**Services list says "Booking options are temporarily unavailable"** — as an admin you'll see the underlying API error instead. Most common causes: wrong data center region, revoked token, or Zoho rate limiting.

**Wrong region** — the region in plugin settings must match the data center where your Zoho account lives (check which domain you log in at: zoho.com, zoho.eu, zoho.in, …). Reconnect after changing it.

**Embed shows a blank frame** — verify the booking page URL is public, and that no security plugin or CSP header on your site blocks iframes.

**Nothing shows for visitors, but I see an error message** — setup/error hints render only for users with `manage_options`. Visitors see nothing (or a generic message) until configuration is completed.

**Booking creation fails** — check the error detail in the admin notice. Common causes: the slot was taken between loading and submitting, the OAuth connection expired, or the Zoho Free plan restricts the operation for your workspace.

**No time slots appear for a date** — Zoho reports no availability for that service/staff/date combination. Verify the staff member's working hours in Zoho Bookings.

**Times in WordPress don't match Zoho** — timezone mismatch. Make sure the WordPress timezone (Settings → General) matches your Zoho Bookings workspace timezone; the plugin sends the WordPress timezone with each booking.

**MCP: Why am I receiving a 401 or 403 error when connecting?**
This typically indicates an incorrect MCP key, the MCP feature is disabled in your plugin settings, or the Authorization header is missing from the request. Verify your credentials and ensure the endpoint is active in your WordPress dashboard.

**MCP: The agent is connected, but the tools return a 'not connected' status.**
This occurs when the Zoho Bookings OAuth authentication has not been completed within the WordPress admin area. Navigate to your plugin settings and finish the OAuth flow to authorize the connection.

**MCP: Is it safe to share my MCP key with others?**
No, you should never share your MCP key because it grants full control over your Zoho Bookings appointments. If a key is ever compromised, use the "Regenerate" option in your settings to immediately revoke access.

**MCP: Can I connect to the MCP endpoint over plain HTTP?**
While the endpoint may function over HTTP, it is strongly discouraged as your credentials and booking data will be transmitted in plain text. Always use an HTTPS connection to ensure all data remains encrypted and secure.

**MCP: Which AI clients can connect to this endpoint?**
You can connect any MCP-compliant client, including Claude Code (native HTTP), Claude Desktop (via mcp-remote), or any custom-built AI agent. As long as the client supports the Model Context Protocol, it can manage your appointments.

**### Service creation fails – what could be wrong?**

- **Plan limits**: free plan may restrict workspaces/services. Check your Zoho subscription.
- **Resource type**: only `one_on_one` is supported. `resource` services are not yet available.
- **Validation errors**: name may contain forbidden characters, duration must be multiple of 5, meeting mode `online` requires a meeting type.
- **API quota**: ensure you have remaining API calls (250/day on free plan).

**Staff add fails – common causes**

- **Duplicate email**: Zoho rejects staff with an email already in use.
- **Plan limit**: free plans allow only 1 staff member. If one already exists, adding another will fail.
- **Missing required fields**: name and email are mandatory; email must be valid.
- **API error**: check the detail message in the admin notice for the exact reason.

**Why can't I edit or remove staff from WordPress?**

Zoho’s v1 API does not provide endpoints for updating or deleting staff. The `add_staff` tool and page are the only available operations. To modify or remove existing staff, please log into the [Zoho Bookings admin interface](https://bookings.zoho.com) (region‑agnostic link). This is a limitation of the Zoho API, not our plugin.

## Uninstall

Deleting the plugin removes all its options, stored OAuth tokens and cached transients (see `uninstall.php`).

## Taking payments with Stripe (v1.4)

Collect payment for paid services with Stripe Checkout — customers pay on Stripe's secure hosted page, so no card data ever touches your site.

1. In Stripe, grab your API keys (test keys for a sandbox).
2. **Settings → Zoho Bookings → Payments (Stripe)**: tick **Enable payments**, paste the publishable and secret keys, set your 3-letter currency (e.g. `usd`, `cad`). Save.
3. Add the native booking form to any page: `[zoho_bookings_book]`.

How it works: the booking form lets a visitor pick a service, trainer/staff, date and time slot, and enter their details. If the chosen service has a price and payments are enabled, they're sent to Stripe Checkout; the appointment is created only after payment succeeds. Free services (price 0) are booked immediately. Prices are always read server-side from your Zoho services — the amount can't be tampered with from the browser.

AI agents can also generate a payment link with the MCP tool `create_payment_link` (returns a Stripe Checkout URL for a paid service).

`// FUTURE:` deposits, subscriptions and refunds can be layered onto the same `GX_ZB_Stripe` class.

## Service landing pages (v1.5)

**Settings → Zoho Bookings → Service landing pages → Generate / refresh landing pages** creates one WordPress page per service, each showing that service and a **Book & Pay** button backed by its own Stripe payment link (paid services) or the inline booking form (free services). Re-run any time to refresh links or pick up new services. Each page is built from Gutenberg blocks — a heading, an intro paragraph, and the dynamic **Zoho Bookings Service** block (also insertable from the block inserter) — so you can freely edit and rearrange them in the block editor. The `[zoho_bookings_service id="…"]` shortcode remains available for classic content.

## Paid-plan features (v2.0)

To use resource booking, group booking, or custom fields you need a paid Zoho Bookings plan. These features are configured under **Settings → Paid-plan features** (and, for custom fields, **Zoho Bookings → Custom Fields**).

**Enable CRM sync** to push confirmed bookings into Zoho CRM. Toggle "Zoho CRM sync" on, then reconnect to Zoho (disconnect, then Connect again) so the additional `ZohoCRM.modules.ALL` scope is granted. Once connected, every confirmed booking upserts a Contact (matched by email) and logs a meeting/Event in CRM. If a CRM call fails, the booking is never blocked — the error is logged.

**Select an active workspace** from the "Active workspace" dropdown. Services, staff and appointments are then read from that workspace. Switch at any time.

**Define custom fields per service** under **Zoho Bookings → Custom Fields**: pick a service, add rows (label, type, whether required), and save. The fields appear on that service's booking form and are sent to Zoho as `additional_fields`.

**View reports** under **Zoho Bookings → Reports**: total revenue, booking counts by status, revenue by service and bookings per staff, with a date-range filter. The same data is available to AI agents via the MCP tool `get_reports`.

## Changelog

### 2.1.0 — Resource services from WP, real resource availability, voice-agent guide
- **Create resource services from WordPress**: the Services page now has a Service Type selector (one-on-one / resource) and Assign Resources checkboxes on both the create and edit forms (`service_type` + `assigned_resources` API params, web-verified). Group/collective services still must be created in the Zoho Bookings admin — the API has no group creation support.
- **Real resource availability**: the booking form's resource flow now uses the same slot picker as staff bookings — `availableslots` is queried with `resource_id` (also supports `group_id` in the API client), so callers can only pick genuinely free times. The free-typed start-time input is gone.
- **Voice agents guide**: new [VOICE-AGENTS.md](VOICE-AGENTS.md) with step-by-step instructions for connecting the MCP endpoint to Vapi, ElevenLabs Agents, Bland, Dograh, and xAI Grok Voice, plus a suggested agent prompt and troubleshooting table.
- Housekeeping: stale `FUTURE (paid plan)` comments removed/corrected now that those features exist.

### 2.0.0 — Paid-plan features
- **Resource booking**: services can be booked against a specific resource with a start and end time. New API support plus MCP tools `book_resource` and `list_resources`.
- **Group / collective booking**: book a service for a group (all attendees share one slot) via the new MCP tool `book_group` (uses a `group_id`).
- **Custom booking fields**: define per-service extra fields (text, textarea, select, checkbox, required) under **Zoho Bookings → Custom Fields**. They render on the booking form and are sent to Zoho as `additional_fields`.
- **Multiple workspaces**: choose an active workspace under **Settings → Paid-plan features**; services, staff and appointments are read from it.
- **Team / multi-staff**: removed the free-plan one-staff messaging — add as many staff as your Zoho plan allows.
- **Revenue & bookings reports**: new **Zoho Bookings → Reports** page with totals, counts by status, revenue by service and bookings by staff, plus a date-range filter. Also available via the MCP tool `get_reports`.
- **Zoho CRM sync** (optional): each confirmed booking upserts a Contact (by email) and logs a meeting/Event in Zoho CRM. Enable under **Settings → Paid-plan features** and reconnect to Zoho so the extra scope (`ZohoCRM.modules.ALL`) is granted. CRM failures never block a booking.
- Companion simulator updated (v1.5.0) to demo resources and CRM sync with fake data.

### 1.9.0
- Per-staff video conference links: each staff member gets their own meeting URL (Google Meet, Zoom, …) on the Staff page. The link becomes the calendar event location, is added to the event description, and shows as a **Join video call** button on the booking confirmation. (The Zoho Bookings API has no staff video field, so links are stored site-side.)
- Remove/restore staff from WordPress: the Staff page now has a **Remove** action that hides a member from all booking UIs on this site (front-end form, admin New Booking) with one-click **Restore**. Removal server-side blocks forged bookings too. The Zoho account itself is still deleted in the Zoho Bookings admin — the API has no staff delete endpoint (verified 2026-07-08).

### 1.8.0
- New **Zoho Bookings Form** block: the native booking form is now insertable anywhere from the block editor, with sidebar controls for a preselected service and which data to collect (phone on/off, phone required on/off, optional notes field). Shortcode equivalents: `show_phone`, `require_phone`, `show_notes`.
- Time-first paid flow everywhere: service landing pages now always show the booking form — the visitor picks staff, date and time, then pays in Stripe Checkout, and the appointment is only created after payment is confirmed. The services grid "Book & Pay" button links to the landing page (raw payment links remain only as a fallback when no page exists).
- Optional notes are stored with the customer details on the appointment.

### 1.7.1
- Booking form preselects the chosen service: service landing pages pass their service into `[zoho_bookings_book service="…"]`, and a `?gx_zb_service=` query arg deep-links any page holding the form. Staff list and price note load automatically for the preselected service.

### 1.7.0
- Services block: editable button text — "Book button text" and "Book & Pay button text" controls in the block sidebar (`book_label` / `pay_label` shortcode attributes).
- Settings → Services block → Custom CSS: stylesheet textarea that loads wherever the services grid renders, so the cards can be styled to match any theme.
- Booking confirmed screen: the form is replaced by a confirmation panel with the appointment details and **Add to Google Calendar** / **Apple-Outlook .ics** links, plus a "Book another appointment" link (new `GX_ZB_Calendar` class).
- Booking form contrast/accessibility pass: darker slot and note text, visible focus outlines, higher-contrast disabled button.

### 1.6.0
- Services grid block/shortcode (`[zoho_bookings_services]`): shows real prices from Zoho's `cost` field in the Stripe currency, a **Free** label for free services, a **Book & Pay** button linking each paid service's Stripe payment link, and service titles linking to their landing pages.
- Fixed: front booking form's staff and slot dropdowns never loaded (JS called AJAX actions `gx_zb_book_staff`/`gx_zb_book_slots` but PHP registers `gx_zb_staff`/`gx_zb_slots`).
- Fixed: the booking form nonce was echoed outside the form markup (`wp_nonce_field` default echo), so every submit failed with "Security check failed."
- Fixed: `esc_url_raw` stripped the braces from Stripe's `{CHECKOUT_SESSION_ID}` placeholder in `success_url`, so paid bookings could never be confirmed on return. The placeholder is now shielded through the escape.

### 1.5.0
- Per-service landing pages: one WordPress page per service with its own Stripe payment link, generated from Settings. New `[zoho_bookings_service id]` shortcode and `GX_ZB_Stripe::create_payment_link()` (Product→Price→Payment Link).
- Generated landing pages are full Gutenberg block content (heading + intro + a dynamic **Zoho Bookings Service** block), so they open and edit natively in the block editor. Refreshing links no longer overwrites edited pages.

### 1.4.0
- Stripe payments: native `[zoho_bookings_book]` booking form that routes paid services through Stripe Checkout (hosted, PCI-safe) and only books after payment; free services book directly.
- Settings → Payments (Stripe) section; server-side price enforcement; MCP `create_payment_link` tool (16 tools total).

### 1.3.0
- Set up your booking catalog from WordPress: Services page (create / edit / activate / deactivate / delete services, create workspaces) and Staff page (add staff; existing staff are managed in Zoho as its API has no edit/delete).
- API client: createworkspace, createservice, editservice, deleteservice, addstaff — form-encoded, cache-invalidating, with pre-validation.
- 5 new MCP tools (15 total): create_workspace, create_service, update_service, delete_service (guarded destructive), add_staff.

### 1.2.0
- Built-in MCP server (Streamable HTTP, JSON-RPC 2.0) at `/wp-json/gx-zb/v1/mcp` so AI booking agents can list services, check availability and create/reschedule/manage appointments.
- Bearer API-key auth with one-click generate/regenerate in settings, plus enable/disable toggle.
- 10 agent tools with validated inputs and agent-friendly error messages.

### 1.1.0
- Booking management inside wp-admin: top-level Zoho Bookings menu with Dashboard (stats + today's list), Appointments list (filters, pagination, complete/cancel/no-show row actions), New Booking page with live slot picker, and reschedule flow.
- Admin bar quick menu with today's booking count.
- API client: appointments listing with filters, booking creation, reschedule, status updates — all cache-invalidating, form-encoded per Zoho API requirements.

### 1.0.0
- Initial release: embed mode, API mode (OAuth2, region-aware), services display, shortcodes + blocks, caching, admin status panel and notices.
