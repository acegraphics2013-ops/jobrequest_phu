HPU PHASE 4 – COMMUNICATION CENTER

INSTALLATION
1. Back up your project and database.
2. Import: database/request_messages.sql
3. Copy admin/view.php to your project admin/view.php.
4. Copy includes/request_chat_box.php to includes/.
5. Copy the three PHP files from ajax/ to your project's ajax/ folder.
6. Copy assets/css/request_chat.css and assets/js/request_chat.js.
7. Create uploads/chat/ and make it writable by PHP.

FEATURES
- Requestor ↔ HPU staff conversation on the request page
- AJAX send and 5-second message refresh
- Reply to a message
- File attachments up to 10 MB
- Internal notes available only to staff, supervisors, and administrators
- Read-position tracking table
- Optional activity-log insertion when request_activity_logs is compatible
- Existing request access control respected

IMPORTANT
- The code expects session keys user_id and role.
- It uses session name, full_name, or username as sender display name.
- Allowed attachment extensions: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, XLS, XLSX, ZIP.
- For production, also enforce upload restrictions in your web-server configuration.


COMPATIBILITY FIX
- Removed the PHP 8.1-only `never` return type from chat_bootstrap.php.
- AJAX now always returns JSON and displays the actual PHP/MySQL error instead of Unexpected token '<'.
- Compatible with PHP 8.0+ (required by the existing view.php match expression).
