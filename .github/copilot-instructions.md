# Motor Vehicle Repairs Backend - AI Coding Instructions

## Project Overview
A **PHP REST API backend** for a vehicle repair ticketing system serving the Kenya Revenue Authority (KRA). Manages vehicle repairs, support tickets, user workflows, and integrates with SAP/iSupport ERP, AD authentication, SMS, and email systems.

## Architecture & Data Flow

### Core Components
- **API Layer** (`api/`): RESTful endpoints for tickets, vehicles, users, reports - handles CORS, authentication
- **Authentication** (`auth-helper.php`, `functions.php`): Token-based (Bearer) with database persistence, auto-refresh on access
- **Integration Layer** (`config.php`): External systems - SAP iSupport (ERP), Active Directory, SMS (mservices), SMTP email
- **Worker Processes** (`workers/email-worker.php`): Background job that pulls from `emails_queue` table, retries failed emails with exponential backoff
- **Template Engine** (`templates/`): HTML/PDF generation for tickets using mPDF and PHPSpreadsheet

### Request Flow
1. Client sends request with `Authorization: Bearer {token}` header
2. API endpoint validates token via `isTokenValid()` → database lookup
3. On valid token, `refreshToken()` updates expiry (based on `$tokenRefreshDuration` from config)
4. Business logic executes, often calling `getProperties()` for data lookups
5. Actions logged via `logAction()` (user, timestamp, IP, action code)
6. Email notifications queued to `emails_queue` table (async processing)

## Project Conventions & Patterns

### API Response Format
All endpoints follow consistent JSON response structure. Reference [create-ticket.php](api/create-ticket.php#L1-L50):
```php
$httpResponseCode = 400;
$error = "Error Type";
$message = "Descriptive message";
// On success: http_response_code(200); $response_array = [...];
echo json_encode(['success' => true/false, 'error' => $error, 'message' => $message]);
```

### Authentication Pattern
**Every protected endpoint must**:
1. Extract Bearer token from `Authorization` header
2. Call `isTokenValid($token)` (returns bool)
3. Call `refreshToken($token)` (updates expiry in tokens table)
4. Get user info: `getProperties('tokens', 'user_ad_account', 'token', $token)`
5. Return 401 on auth failure with JSON error response

See [send-email.php](api/send-email.php#L20-L35) for standard pattern.

### Database Access
- Uses `mysqli` prepared statements to prevent SQL injection
- Global `$conn` (MySQLi connection object)
- Timezone: Africa/Nairobi (set in [functions.php](functions.php#L2-L3))
- Helper function `getProperties($table, $column, $key, $value)` for single lookups (inefficient for bulk - use direct queries instead)

### Email Workflow (Async Queue Pattern)
1. APIs insert records into `emails_queue` table with `status='pending'`
2. `[email-worker.php](workers/email-worker.php)` runs via cron/scheduler, pulls pending emails
3. Worker uses PHPMailer to send via SMTP (config in config.php: `$emailHost`, `$emailPort`)
4. Failed emails retry up to `$maxRetries` (currently 3) before marking failed
5. Templates use `email-body.php` with variable substitution

**Key config flags**: `$allow_send_email` (disable to prevent email sending), `$emailsLimit` (batch size per run)

### External Integrations
- **SAP iSupport ERP**: Dev endpoint `http://erp01-dev.dc01.kra.go.ke:8000` - used for vehicle data
- **Active Directory**: `$adBaseUrl = "http://10.153.1.64:8595"` - user authentication
- **SMS Gateway**: `$smsappurl = "http://10.150.1.118:8076/sms/send"` (see [send-sms.php](api/send-sms.php))
- **Email SMTP**: Non-auth relay on `10.150.11.11:25`
- **Encryption Key**: `$loginEncKey` for password encryption (currently bypassed for dev in [login.php](api/login.php#L50))

### CSV/Excel Templates
Defined in `templates/`:
- `users-upload-template.csv` - bulk user import format
- `vehicle-upload-template.csv` - bulk vehicle import format

## Critical Files to Know
| File | Purpose |
|------|---------|
| [config.php](config.php) | All env vars, external API creds, feature flags (edit for dev/prod switches) |
| [functions.php](functions.php) | Shared utilities: `isTokenValid()`, `refreshToken()`, `logAction()`, `getProperties()` |
| [auth-helper.php](auth-helper.php) | Centralized auth validation (returns status + error or token + user data) |
| [api/login.php](api/login.php) | AD/iSupport integration, token creation |
| [api/create-ticket.php](api/create-ticket.php) | Exemplar for standard CRUD + auth pattern |
| [workers/email-worker.php](workers/email-worker.php) | Background email processor - run via scheduler |
| [email-body.php](email-body.php) | Email HTML template (included dynamically) |

## Common Tasks

### Adding a New API Endpoint
1. Create file in `api/{action}.php`
2. Add CORS headers + OPTIONS handler (see [create-ticket.php](api/create-ticket.php#L1-L9))
3. Check auth at start (see [send-email.php](api/send-email.php#L18-L35) for pattern)
4. Use prepared statements for queries
5. Return JSON with consistent format
6. Log significant actions via `logAction()`

### Debugging Auth Issues
- Check `tokens` table for active/expired entries
- Verify `$tokenRefreshDuration` isn't too short
- Ensure `Authorization: Bearer {token}` header format is exact
- All timestamps use "Y-m-d H:i:s" format in Africa/Nairobi TZ

### Running Email Worker
```bash
php workers/email-worker.php
```
Worker will fetch from queue until `$emailsLimit` reached or no pending emails.

## Security Notes
- All database queries use prepared statements (no string interpolation in dynamic SQL)
- Tokens stored in DB, checked against expiry timestamp before validation
- IP address captured in logs (via `getIp()` function)
- Email addresses validated before sending
- Config file contains sensitive credentials (dev values shown; update for production)

## Performance Considerations
- `getProperties()` is a single-row lookup; avoid in loops - use batch queries instead
- Email processing is async (queue-based) to prevent request timeouts
- Token refresh happens on every request (acceptable at current scale)
- Prepared statements reduce SQL injection risk but still execute individual queries

## Timezone & Timestamps
- All timestamps: `date("Y-m-d H:i:s")` in Africa/Nairobi timezone
- Token expiry calculated: `date("Y-m-d H:i:s", strtotime($timestamp) + $tokenRefreshDuration)`
- Ensure all datetime comparisons use same format/timezone
