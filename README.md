# uploader — Large-file upload with sharing

Upload large files in chunks and share them via email-delivered public links.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk) — developed for the ScienceData cloud platform.  
**License:** AGPL-3.0

## Overview

`uploader` provides a dedicated upload page for large files that works around browser and proxy timeout limits by splitting files into chunks. After upload, public share links can be generated and optionally emailed to recipients.

Key features:

- **Chunked upload** — files are split into 300 MB pieces sent sequentially; the server reassembles them before writing to Nextcloud storage
- **Grant folder support** — members of `user_group_admin` grant groups can upload directly into their group grant folder
- **Destination folder** — users can choose a subfolder within their home or grant folder as the upload target
- **Public link sharing** — after upload, generates Nextcloud public share links (with optional expiry date and password) and emails them to one or more recipients
- **Personal settings** — users can save a default upload folder and default grant group

## Requirements

- Nextcloud 34+
- PHP 8.2+
- `user_group_admin` (optional) — required for grant folder upload targets

## Installation

```bash
occ app:enable uploader
```

No database migrations. The app stores user preferences via Nextcloud's `IConfig` user-value store.

## Usage

Navigate to the **Upload** entry in the left sidebar. Select a file, optionally choose a destination folder and grant group, then click Upload. Once complete, optionally fill in recipient email addresses and click Share to send public links by email.

## Personal settings

Under **Settings → Personal → Uploader**, users can save:

- **Default upload folder** — pre-fills the destination folder field on the upload page
- **Default upload group** — pre-selects a grant group if the user belongs to one or more

## Architecture

### Chunked upload flow

1. The browser splits the selected file into 300 MB chunks and POSTs each to `POST /upload` with `fileName`, `fileIndex`, and the raw file data.
2. The server writes each chunk to a temporary directory (`{datadirectory}/{uid}/cache/uploader/`).
3. When the final chunk arrives (`fileDone` parameter set), the server concatenates all chunks into an assembled temp file, writes it into the target Nextcloud folder via the Files API, and removes the temp files.
4. The browser displays the returned file path and offers the Share button.

Uploads can be cancelled via `POST /upload/cancel`, which removes any partial chunks from the temp directory.

### Share flow

`POST /share` accepts a list of `{path, group, filename}` objects. For each path the server:

1. Resolves the NC file node (in the user's home or grant folder).
2. Creates a `TYPE_LINK` share with `PERMISSION_READ` via `IShareManager`.
3. Optionally sets an expiry date and password.
4. Returns the public share URL.

If recipient addresses are supplied, a plain-text and HTML email is sent to each address with links to all shared files.

### Grant folder resolution

When a `group` parameter is supplied, the file node is resolved relative to `.uga_grants/{gid}/` inside the user's home folder (the same path used by `user_group_admin`'s DAV endpoint). Without a group, files land in the user's standard home folder.

## API

All endpoints require a valid Nextcloud session (`NoAdminRequired`).

| Method | URL | Description |
|--------|-----|-------------|
| `GET`  | `/apps/uploader/` | Upload page |
| `POST` | `/apps/uploader/upload` | Upload one chunk |
| `POST` | `/apps/uploader/upload/cancel` | Cancel upload, remove chunks |
| `POST` | `/apps/uploader/share` | Create share links, send email |
| `GET`  | `/apps/uploader/settings` | Get personal settings |
| `POST` | `/apps/uploader/settings` | Save personal settings |

### `POST /upload` parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `fileName` | string | Original filename |
| `fileIndex` | int | Zero-based chunk index |
| `destination` | string | Target subfolder path (optional) |
| `group` | string | Grant group ID (optional) |
| `fileDone` | string | Any value triggers assembly of all chunks |
| `fileToUpload` | file | The chunk data |

### `POST /share` parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `files` | array | `[{path, group, filename}, …]` |
| `recipient` | string | Space/comma/semicolon-separated email addresses |
| `expiration` | string | ISO date string for link expiry (optional) |
| `password` | string | Share password (optional) |

## Deployment

No build step. Pure PHP + plain JS.

```bash
# Deploy to all nodes
rsync -av --delete apps/uploader/ master:/var/www/nextcloud/apps/uploader/
rsync -av --delete apps/uploader/ silo1:/var/www/nextcloud/apps/uploader/
rsync -av --delete apps/uploader/ silo2:/var/www/nextcloud/apps/uploader/

# Enable on each node
occ app:enable uploader
```
