# Settings

All WordPress options registered and used by Alynt Customer Groups.

## Options

| Option Key | Type | Default | Sanitization | Tab | Description |
|------------|------|---------|--------------|-----|-------------|
| `wccg_default_group_id` | int | `0` | `absint` | Customer Groups | The customer group ID applied to ungrouped customers. `0` means no default group is active. |
| `wccg_default_group_custom_title` | string | `''` | `sanitize_text_field` | Customer Groups | Custom frontend label shown when the default group is active. If empty, the group's own name is used. |
| `wccg_version` | string | — | None (set once on activation) | System | Installed plugin version string (e.g. `1.1.0`). Used to detect upgrades and run version-specific migrations. |
| `wccg_installation_date` | string | — | None (set once on activation) | System | MySQL datetime string recording when the plugin was first activated. |
| `wccg_last_cleanup` | int | `0` | None (Unix timestamp) | System | Unix timestamp of the last successful cleanup task run. |

## Notes

- `wccg_default_group_id` and `wccg_default_group_custom_title` are managed from the **WooCommerce > Customer Groups** admin screen.
- `wccg_version` and `wccg_installation_date` are set automatically on plugin activation and should not be modified manually.
- `wccg_last_cleanup` is updated automatically by the daily `wccg_cleanup_cron` scheduled event.
