---
paths:
  - '{app/Support/Media/{ExpireInactiveUploads.php,TusHookHandler.php,TusTransportClient.php},deploy/nginx/**}'
---

# Nginx

## Expire tus resources only through verified internal termination
Inactive expiry applies only to due pending/uploading/paused uploads. Before DELETE, require tus length/offset, guarded regular-file size, disk device, and unchanged DB activity to agree; preserve uncertainty and transport failures. Mark expiry DELETEs with the hook secret, strip that header at public Nginx, and let locked hooks distinguish Expired from ordinary Cancelled.
