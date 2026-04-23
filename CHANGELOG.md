# Release Notes for Craft Backup

## Unreleased
### Added
- Initial scaffold: `backup/run`, `backup/list`, `backup/clean`, `backup/publish-config` console commands.
- Local and SFTP targets.
- tar.gz compression via `PharData`.
- Streaming AES-256-CBC encryption with HMAC-SHA256 authentication.
- GFS retention policy.
- Optional throughput throttling for uploads.
