# WP Dual-Native Abilities

**The Agentic Bridge for the WordPress AI Ecosystem.**

This plugin demonstrates **Composability** by wiring together the three critical building blocks of WordPress AI into a unified, safe workflow:

1.  **Data Layer:** [Dual-Native API](https://github.com/antunjurkovic-collab/wp-dual-native) (Machine Representation & Safe Writes).
2.  **Logic Layer:** [Abilities API](https://github.com/WordPress/abilities-api) (Routing & Registration).
3.  **Transport Layer:** [WP AI Client](https://github.com/WordPress/wp-ai-client) (LLM Connectivity).

---

## What It Does

When activated, this plugin registers a suite of **Core Abilities** that allow AI Agents to read, understand, and modify WordPress content safely.

### 1. The "Eyes" (Read Abilities)
*   **`dni/get-post-mr`**: Fetches the **Machine Representation (MR)** of a post.
    *   *Benefit:* Saves ~60% of tokens compared to raw HTML by stripping theme markup.
    *   *Meta:* Returns `etag` (CID) and `last_modified` for caching.
*   **`dni/get-post-md`**: Fetches the **Markdown** representation of a post.
    *   *Format:* Pure text/markdown with ETag support.
*   **`dni/get-catalog`**: Returns a lightweight index of content (supports `since`, `status`, and `types` filters) for efficient discovery.

### 2. The "Hands" (Write Abilities)
*   **`dni/insert-blocks`**: Performs **Atomic Mutations** (append, prepend, insert at index).
    *   *Safety:* Enforces **Optimistic Locking** via `if_match`. If the content changed since the Agent read it, the write fails (`412 Precondition Failed`) to prevent data loss.
    *   *Integrity:* Handles server-side block serialization to prevent HTML corruption.

### 3. The "Brain" (AI Integration)
*   **`dni/ai-suggest`**: Uses the official **WP AI Client SDK** to generate summaries and tags.
    *   *Fallback:* If the SDK is not present, falls back to a deterministic heuristic.
*   **`dni/generate-title`**: AI-powered title generation with safe update.
    *   *Safety:* Supports `If-Match` for optimistic locking.
*   **`dni/agentic-summarize`**: The "Super-Ability" reference implementation.
    *   *Workflow:* Reads MR (Data) → Generates Summary (SDK) → Appends Block (Safe Write).
    *   *Default Safety:* Automatically uses current CID for `If-Match` if not provided.

---

## REST Polyfill (Works Today)

If the **Abilities API** (WordPress 6.9+) is not yet installed on the site, this plugin automatically falls back to exposing these features via standard REST endpoints.

This allows you to build and test Agentic workflows **right now** on any WordPress site.

### Read Endpoints
*   `GET /wp-json/dni-abilities/v1/mr/{id}` - Machine Representation (JSON)
*   `GET /wp-json/dni-abilities/v1/md/{id}` - Markdown representation
*   `GET /wp-json/dni-abilities/v1/ai/suggest/{id}` - AI suggestions

**Caching:** `/mr` and `/md` return `ETag` and `Last-Modified` headers, and honor `If-None-Match` for 304 responses.

### Write Endpoints
*   `POST /wp-json/dni-abilities/v1/insert` - Insert blocks (supports `If-Match` header)
*   `POST /wp-json/dni-abilities/v1/title/generate` - Generate and update title
*   `POST /wp-json/dni-abilities/v1/agentic/summarize` - Full summarize workflow

**Safety:** All write endpoints forward `If-Match` to the DNI write path and return the current `ETag` in responses.

---

## Requirements

1.  **Required:** [Dual-Native API](https://github.com/antunjurkovic-collab/wp-dual-native) providing `DNI_MR` and `DNI_CID` classes (or a compatible Dual-Native provider).
    *   The bridge fails closed if these classes are not available.
2.  **Optional:** [WordPress AI Client](https://github.com/WordPress/wp-ai-client) for LLM connectivity.
    *   Falls back to deterministic heuristics if not present.
3.  **Optional:** [Abilities API](https://github.com/WordPress/abilities-api) for standard registration.
    *   Falls back to REST polyfill if not present.

## Installation

1.  Ensure `wp-dual-native` is installed and active.
2.  Upload this plugin to `wp-content/plugins/wp-dual-native-abilities`.
3.  Activate via WordPress Admin.
4.  (Optional) Configure your LLM Provider in **Settings > Dual-Native API** or via the AI Client settings.

## Permissions

*   **Read abilities** (`dni/get-post-mr`, `dni/get-post-md`): Require `edit_post` capability on the target post.
*   **Catalog** (`dni/get-catalog`): Requires `edit_posts` capability.
*   **Write abilities** (`dni/insert-blocks`, `dni/generate-title`, `dni/agentic-summarize`): Require `edit_post` + REST nonce.

## Admin Tools

Access **Tools → DNI Agent Console** to test abilities locally:
*   Status lights for DNI, Abilities API, and AI Client
*   Test UI for summarize, insert at index, and safe title generation
*   Useful for validating your setup before deploying agents

## Usage Example (MCP / Agent)

An Agent using this bridge can perform a safe edit loop:

1.  **Call `dni/get-post-mr`** → Get Content + CID (`abc123...`).
2.  **Think** → Generate new content.
3.  **Call `dni/insert-blocks`** → Pass `if_match: "abc123..."`.
4.  **Result** → Success (200) or Conflict (412).

---

**License:** GPL v2 or later
