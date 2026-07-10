# Voice Agents — GX Zoho Bookings MCP

Connect a voice AI agent to your GX Zoho Bookings plugin so callers can check live availability and book appointments by phone. The plugin exposes a Model Context Protocol (MCP) server that exposes 20 booking tools over stateless JSON-RPC. This guide covers enabling the endpoint, securing your API key, and wiring up Vapi, ElevenLabs Agents, Bland, Dograh, and xAI Grok Voice.

## Before you start

1. **Enable MCP:** WP admin → **Settings → Zoho Bookings → "AI Agent Access (MCP)"** → enable the MCP endpoint → copy (or regenerate) your API key.
2. **Endpoint:** `https://YOUR-SITE.com/wp-json/gx-zb/v1/mcp`
3. **Auth header:** `Authorization: Bearer YOUR_MCP_API_KEY`
4. **Recommended voice subset** (keep the tool count small to minimize latency):
   - `list_services`
   - `list_staff`
   - `get_available_slots`
   - `create_booking`
   - `reschedule_booking`
   - `create_payment_link`

**Security:** Treat the MCP key like a password — anyone who has it can create and modify bookings. Use HTTPS only. If the key leaks, rotate it from the settings page immediately. Prefer your platform's secret manager over pasting keys into prompts or code.

## Vapi

**Verdict:** Native MCP support — recommended path.

1. Dashboard → **Assistants** → open or create an assistant.
2. Go to the **Tools** tab → add an **MCP** tool.
3. Enter the server URL and Bearer header.
4. Set protocol to `shttp` (streamable HTTP, the default).
5. **Publish** the assistant.

```json
{
  "model": {
    "tools": [{
      "type": "mcp",
      "function": { "name": "mcpTools" },
      "server": {
        "url": "https://YOUR-SITE.com/wp-json/gx-zb/v1/mcp",
        "headers": { "Authorization": "Bearer YOUR_MCP_API_KEY" }
      },
      "metadata": { "protocol": "shttp" }
    }]
  }
}
```

Vapi fetches the tool list dynamically at call time. Avoid the per-tool `function` wrapper — Vapi POSTs its own payload format (not JSON-RPC), so the MCP route is strongly preferred. Docs: docs.vapi.ai/tools/mcp

## ElevenLabs Agents

**Verdict:** Native MCP support — first-class integration.

1. Go to elevenlabs.io/app/**agents/integrations** → **Add Custom MCP Server**.
2. Fill: Name, Description, Server URL, **Transport = HTTP streamable**, Secret Token.
3. For the Secret Token, enter `Bearer YOUR_MCP_API_KEY` (UNVERIFIED whether the `Bearer ` prefix is auto-added — if you see auth errors, try toggling).
4. Choose a tool approval mode. **Fine-Grained Tool Approval** is recommended: auto-approve read tools (`list_services`, `get_available_slots`), require approval or disable write tools you don't need.
5. Attach the server to your agent: agent settings → add the server ID to `mcp_server_ids`.

API alternative: `POST /v1/convai/mcp-servers`. Docs: elevenlabs.io/docs/eleven-agents/customization/tools/mcp

## Bland

**Verdict:** No native MCP — wrap each tool manually as a Custom Tool.

1. POST to `/v1/tools` for each booking tool you need.
2. Attach the returned `TL-xxxx` tool IDs to calls via `tools: ["TL-…"]` or a Custom Tool node in a pathway.
3. Each tool sends a raw JSON-RPC envelope to the MCP endpoint.

Example (`get_available_slots`):

```json
{
  "name": "GetAvailableSlots",
  "description": "Get open booking slots for a service on a date",
  "speech": "One moment while I check availability.",
  "url": "https://YOUR-SITE.com/wp-json/gx-zb/v1/mcp",
  "method": "POST",
  "headers": { "Authorization": "Bearer YOUR_MCP_API_KEY", "Content-Type": "application/json" },
  "body": {
    "jsonrpc": "2.0", "id": 1, "method": "tools/call",
    "params": { "name": "get_available_slots", "arguments": {
      "service_id": "{{input.service_id}}",
      "staff_id": "{{input.staff_id}}",
      "date": "{{input.date}}"
    }}
  },
  "input_schema": {
    "type": "object",
    "properties": {
      "service_id": {"type":"string"},
      "staff_id": {"type":"string"},
      "date": {"type":"string"}
    },
    "required": ["service_id","staff_id","date"]
  },
  "response": { "slots": "$.result.content[0].text" },
  "timeout": 15000
}
```

Repeat for `create_booking`, `reschedule_booking`, `create_payment_link`, etc. The JSON-RPC wrapping approach and `$.result...` response path are UNVERIFIED against the live server — test before production. Bland v2 Tools (Tools Hub → "rest_api" integration, Connections hold base URL + Bearer secret) are an alternative. Docs: docs.bland.ai/api-v1/post/tools

## Dograh

**Verdict:** Native MCP support — open-source and self-hostable.

1. Create a credential: type **Bearer Token**, token = `YOUR_MCP_API_KEY`. Dograh sends `Authorization: Bearer <token>`.
2. **Tools → MCP Server:** Name, Description (the LLM reads this), URL = `https://YOUR-SITE.com/wp-json/gx-zb/v1/mcp` (must include `https://`), Credential = the Bearer credential.
3. **Save** — Dograh calls `tools/list` and displays the full tool catalog.
4. Attach tools per workflow node. Select **only** the functions each node needs (availability tools on a scheduling node, `create_booking` on a confirm node) and add a node-level prompt describing when to invoke them.

Fallback: HTTP API tool type if the MCP path fails — requires clunky JSON-RPC wrapping. UNVERIFIED: exact UI menu labels and discovery behavior if the server requires a full MCP initialize handshake. Docs: docs.dograh.com/voice-agent/tools/mcp-tool

## xAI (Grok Voice)

**Verdict:** Native MCP via the realtime API — no no-code dashboard; configure programmatically.

Hosted voice agent connects over WebSocket `wss://api.x.ai/v1/realtime?model=grok-voice-latest`. Auth uses an xAI API key (server-side) or ephemeral token (client-side). Configure via a `session.update` event.

**Route A (recommended) — remote MCP tool:** xAI calls your MCP server directly.

```json
{
  "type": "session.update",
  "session": {
    "voice": "eve",
    "instructions": "You are a booking assistant…",
    "turn_detection": { "type": "server_vad" },
    "tools": [{
      "type": "mcp",
      "server_url": "https://YOUR-SITE.com/wp-json/gx-zb/v1/mcp",
      "server_label": "gx-bookings",
      "authorization": "Bearer YOUR_MCP_API_KEY",
      "allowed_tools": ["list_services", "get_available_slots", "create_booking"]
    }]
  }
}
```

Only `server_url` and `server_label` are required. `allowed_tools` trims the 20-tool catalog — use it to reduce latency. UNVERIFIED: whether xAI's MCP client requires streamable HTTP or SSE.

**Route B — plain function calling:** Define `{type:"function", name, description, parameters}` tools; on `response.function_call_arguments.done`, your backend POSTs JSON-RPC `tools/call` to the endpoint, then returns the result via `conversation.item.create` (`function_call_output`, `call_id`) followed by `response.create`. Docs: docs.x.ai/developers/model-capabilities/audio/voice-agent

## Suggested agent prompt

```
You are a phone booking assistant for [Business Name].
1. Greet the caller and ask which service they need.
2. Call list_services if unsure, then list_staff if the caller has a preference.
3. Call get_available_slots to find real openings. NEVER invent availability —
   only offer slots returned by the tool.
4. Read back the selected service, date, time, and staff member; confirm the caller agrees.
5. Call create_booking to finalize.
6. Read back the booking confirmation reference.
7. For paid services, call create_payment_link and tell the caller they will
   receive a payment link by SMS/email.
If any tool fails, apologize and offer to transfer to a human. Keep responses
short and conversational.
```

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| **401 Unauthorized** | Bad or missing Bearer key | Verify the header is exactly `Authorization: Bearer YOUR_MCP_API_KEY`. Regenerate the key from Settings → Zoho Bookings → MCP. |
| **404 Not Found** | MCP endpoint disabled or permalinks not flushed | Re-enable the MCP endpoint in settings. Re-save permalinks (Settings → Permalinks → Save). |
| **Empty tools list** | Zoho not connected | Reconnect Zoho Bookings OAuth in the plugin settings. |
| **Tool errors at runtime** | OAuth token expired or revoked | Reconnect the Zoho OAuth integration and retry. |
| **High latency** | Too many tools exposed to the model | Limit allowed tools to the recommended voice subset (6 tools). Fewer tools = faster model decisions. |

Use HTTPS only and keep your MCP key in a secret manager — never hard-code it in client-side or agent prompts.
