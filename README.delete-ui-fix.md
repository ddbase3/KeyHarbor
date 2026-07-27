# KeyHarbor 0.6.1 delete UI asset fix

This patch keeps the 0.6.0 revoked-credential deletion behavior and changes only the JavaScript asset names used by the user and administrator displays.

The versioned filenames force the host asset resolver to expose a new URL instead of reusing an older public or cached `keymanagement.js` or `keyharboradmin.js` file.

No database migration is required.
