---
paths:
  - 'app/{Actions,Models,Support}/**'
---

# Actions Models Support

## Replacement only targets the tracked primary
Ordinary uploads never overwrite existing files. MUM-011 may replace only an explicitly confirmed application-tracked current primary after full size and ffprobe validation. Never recursively delete a movie directory or touch Jellyfin/user-managed artwork, metadata, subtitles, trickplay, or other sidecars.
