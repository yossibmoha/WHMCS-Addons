# Contabo API WHMCS Module

This folder will contain documentation and assets for the Contabo API WHMCS addon/server module.

- Purpose: Offer all Contabo products under your brand via WHMCS.
- Tech: PHP 8.2, WHMCS 8.13.1.
- Status: Initial placeholder. Content to be filled.

---

### Contabo API WHMCS Module – Functional and Technical Specification

This document defines the scope, features, and technical design for a WHMCS addon and provisioning module that exposes the full Contabo product portfolio under your brand, with full admin control and full client server management. It targets PHP 8.2 and WHMCS 8.13.1.

1) Scope and Goals
- Provide Contabo Compute (VPS/VDS), Object Storage, Private Networks (VPC), Secrets, Users, and Tags via WHMCS.
- Full admin control: credentials, pricing/margins, catalogs, logs, action queue, imports, guardrails.
- Full client control: power, rebuild with cloud-init, SSH keys, snapshots/images, volumes, networking, object storage, tags.
- Configurable options across all features, including cloud-init user-data at order and rebuild time.
- Branded client experience without Contabo branding.

2) Architecture
- Server module: `modules/servers/contabo/` with `lib/` for services, API client wrapper, mappers, validators.
- Addon module: `modules/addons/contabo_admin/` for admin settings, catalogs, pricing, logs, action queue, imports, tools.
- OpenAPI client (generated) under `vendor/contabo-sdk/` or `lib/ApiClient/`.
- DB tables: settings, catalogs (regions/images/sizes), resources, networks, object storage, ssh keys, actions, logs, pricing rules, tags.
- Cron: catalog sync, status sync, usage sync, action queue worker, optional price updater.

3) Security & Auth
- OAuth2 token flow, token cache + auto-refresh, retries/backoff for 429/5xx.
- Encrypt secrets using WHMCS crypto; redact sensitive logs.
- `x-request-id` per call; optional `x-trace-id` for grouped actions.
- Optional Contabo sub-user automation with role templates and tag-scoped access.

4) Admin Features (Addon)
- Settings: credentials, rate limits, guardrails, defaults (tags, cloud-init template, SSH policy).
- Catalog Sync: regions/images/sizes cache with diffs; regenerate WHMCS configurable options.
- Pricing/Margins: rules by region/size/product; rounding; currency mapping; auto-update.
- Logs/Auditing: searchable request logs with request IDs; export.
- Action Queue: async operations (resize/reinstall/volumes/network); retries; override.
- Imports: map existing Contabo resources into WHMCS services.
- Tools: test connection, clear caches, simulate order, validate cloud-init, run cron now.

5) Client Features (Server Module)
- Dashboard: status, region, size, IPs, uptime, tags; start/stop/reboot/rescue.
- OS & Cloud-init: reinstall (image/template), textarea for `user_data`, validation, template variables.
- SSH Keys: client vault; attach/detach; set defaults.
- Snapshots/Images: create/list/restore/delete; convert to image where supported.
- Volumes: create/attach/detach/delete; grow; filesystem guidance.
- Networking: join/leave private networks (with reinstall/restart notices); manage extra IPs if API supports.
- Firewall/Security: rules CRUD if API exposes.
- Object Storage: order/upgrade/cancel; auto-scaling cap; usage; credentials reveal.
- Tags: view/manage allowed tags; auto-applied service tags.
- Activity: module action history with timestamps and `x-request-id`.

6) Configurable Options
- Compute: region; size/flavor; image/template; additional IPv4; private networking (Y/N) + target VPC; additional volumes; backup/snapshot policy; cloud-init template (select) + user-data (textarea via custom field).
- Object Storage: location; capacity tier; auto-scaling enabled; monthly cap.
- Private Network (optional product): location; name/prefix; initial attachments.

7) Custom Fields (per service)
- `contabo_resource_type`, `contabo_instance_id`, `contabo_object_storage_id`, `contabo_private_network_id`,
  `contabo_cloud_init_user_data`, `contabo_cloud_init_template`, `contabo_ssh_keys`,
  `contabo_last_known_state`, `contabo_last_request_ids`, `contabo_action_lock`.

8) Lifecycle Mapping
- CreateAccount: resolve options; provision resource; apply tags; SSH keys; `user_data`; poll to ready; store IDs.
- Suspend/Unsuspend: compute stop/start policy; object storage access policy.
- Terminate: delete resources; optional snapshot; detach/delete volumes; cleanup tags/sub-users.
- ChangePackage: resize if supported; queue; prevent disk shrink; cross-region as rebuild flow.
- Upgrade/Downgrade: apply incremental changes respecting constraints.
- ChangePassword: root reset if supported.
- ClientArea: tabs and actions as above; TestConnection for admin.

9) API Coverage (from OpenAPI)
- Users/Roles, Compute (instances, snapshots, images, volumes, IPs), Private Networks, Object Storage, Secrets, Tags.
- Always send `x-request-id`; handle pagination and common response envelope.

10) Database Tables
- `mod_contabo_settings`, `mod_contabo_catalog_regions`, `mod_contabo_catalog_images`, `mod_contabo_catalog_sizes`,
  `mod_contabo_resources`, `mod_contabo_networks`, `mod_contabo_object_storage`, `mod_contabo_ssh_keys`,
  `mod_contabo_actions`, `mod_contabo_logs`, `mod_contabo_pricing_rules`, `mod_contabo_tags`.

11) Resiliency & Cron
- Retries/backoff for transient errors; idempotent checks.
- Cron: 15m action queue; hourly status; daily catalogs/pricing; daily usage sync (object storage).

12) Cloud-init
- Templates with variables (e.g., `${client_email}`, `${service_id}`, `${hostname}`); resolved at runtime.
- Textarea custom field for user-data; validation (YAML/size); optional base64; reinstall supports edits.

13) Pricing & Billing
- Auto-generate config options from catalogs; apply margin rules; support prorated upgrades.
- Optional billing policies for snapshots/volumes; otherwise enforce quotas.

14) Imports & Sync
- Import wizard to map existing Contabo resources to WHMCS services via tags/IP/name.
- On-demand and scheduled syncs for catalogs, status, usage.

15) Observability
- Structured logs with request IDs; client-visible action history; export.

16) Policies & Guardrails
- Toggles: destructive ops, client VPC creation, SSH-required, cloud-init limits, suspend policy.

17) I18n & Branding
- Language files; branded client UI.

18) Versioning & Tests
- Semantic versioning; DB migrations; unit/integration tests; acceptance test checklist.

19) Milestones
- Foundation → Catalog/Config → Compute MVP → Compute Advanced → Object Storage → Users/Secrets/Tags → Cron/Resilience → Polish.
