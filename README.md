# Share Files

Small PHP file-sharing dashboard for a BaoTa-managed site.

Visitors can download shared files and copy public links. Expand “管理登录” to sign in; managers can upload one file at a time (up to 45 MiB) and delete files after confirmation. Existing names are never overwritten.

Files remain in `files/`, outside the public web directory. Deletion moves the content and its original name/download metadata to `storage/trash/`; a server administrator can recover it. Deleted public links return 404. There is no automatic trash cleanup.

Authentication uses a salted password hash in the private `storage/manager.json` (owner www, mode 0600). Management sessions expire after 12 hours. Keep the entire `storage/` directory out of version control and outside the web root.
