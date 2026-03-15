# Notification Service (Port 8002)

Stateless Laravel 12 JSON API that serves as the **orchestration hub** of the Notification Platform. It coordinates across User Service, Template Service, and Messaging Service to validate users, render templates, enforce rate limits, and dispatch multi-channel notifications — all with idempotency protection.

## Responsibilities

- Notification creation with full orchestration: validate user, check preferences, render template, dispatch delivery.
- Idempotency protection via `(user_uuid, idempotency_key)` composite uniqueness.
- Per-user rate limiting (5 notifications/minute).
- Notification status tracking (queued / sent / failed).
- Per-channel delivery attempt tracking with provider details.
- Notification retry support.
- Notification listing with filters (status, user_uuid, template_key).

## Database

**Database:** `np_notification_service`

| Table | Purpose |
|-------|---------|
| `notifications` | Core notification records: user, template, channels, variables, status, delivery references |
| `idempotency_keys` | Deduplication records keyed by `(user_uuid, idempotency_key)` with request hash |
| `notification_attempts` | Per-channel attempt records with status, provider, and error tracking |
| `cache` | Laravel cache (used for rate limiting) |
| `jobs` | Laravel queue jobs (standard) |

## API Endpoints

All routes are prefixed with `/api/v1` and require JWT authentication.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | Service health check |
| `GET` | `/notifications` | List notifications (filterable by status, user_uuid, template_key) |
| `POST` | `/notifications` | Create and orchestrate a notification |
| `GET` | `/notifications/{uuid}` | Get notification details with attempts |
| `POST` | `/notifications/{uuid}/retry` | Retry a failed notification |

### Create Notification Payload

```json
{
  "user_uuid": "uuid",
  "template_key": "welcome_email",
  "channels": ["email", "push"],
  "variables": { "name": "Alex" },
  "idempotency_key": "optional-unique-key"
}
```

## Orchestration Flow

When a notification is created, the service executes this pipeline:

1. **Idempotency check** — if `idempotency_key` is provided and already exists, return the existing notification.
2. **Validate user** — call User Service to verify the user exists and is active.
3. **Fetch preferences** — call User Service for the user's channel preferences; intersect with requested channels.
4. **Rate limiting** — enforce 5 notifications/minute per user via cache throttle.
5. **Render template** — call Template Service to compile subject/body with provided variables.
6. **Create notification** — persist notification record with status `queued`.
7. **Store idempotency key** — persist deduplication record with request hash.
8. **Create attempts** — create `NotificationAttempt` records for each channel.
9. **Dispatch to Messaging Service** — send delivery requests with per-channel recipient/content data.
10. **Update status** — mark notification as `sent` or `failed` based on messaging response.

## Architecture

- **Tech**: Laravel 12, PHP 8.2, MySQL.
- **Auth**: RS256 JWT validation via `JwtAdminAuthMiddleware`. Tokens are issued by User Service.
- **Middleware**:
  - `CorrelationIdMiddleware` — propagates `X-Correlation-Id` across all requests and outbound calls.
  - `RequestTimingMiddleware` — logs structured JSON with method, route, status, latency, actor.
  - `JwtAdminAuthMiddleware` — validates Bearer token.
- **Service Clients**: `UserServiceClient`, `TemplateServiceClient`, `MessagingServiceClient` — all use the `MakesHttpRequests` trait for consistent HTTP handling, token forwarding, correlation ID propagation, latency logging, and error extraction.
- **Responses**: Standardized API envelope (`success`, `message`, `data`, `meta`, `correlation_id`).

## Inter-Service Dependencies

| Service | Purpose |
|---------|---------|
| User Service (8001) | Validate user existence/activity, fetch channel preferences and contact info |
| Template Service (8004) | Render template with variables |
| Messaging Service (8003) | Dispatch per-channel delivery requests |

## Local Setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve --port=8002
```

Requires MySQL with database `np_notification_service` created. Configure service URLs in `.env`:

```env
USER_SERVICE_URL=http://localhost:8001/api/v1
MESSAGING_SERVICE_URL=http://localhost:8003/api/v1
TEMPLATE_SERVICE_URL=http://localhost:8004/api/v1
```

## Testing

```bash
php artisan test
```

Tests run against MySQL database `np_notification_service_test` (configured in `phpunit.xml`). External service calls are mocked via Laravel's service container.

**Test coverage:** 17 tests, 117 assertions — covers orchestration flow, idempotency, rate limiting, external service failures, validation, auth, and retry.

## Notes

- The orchestrator forwards the admin's Bearer token and correlation ID to all downstream service calls.
- If a user's preferences exclude all requested channels, the notification is rejected before creation.
- `ExternalServiceException` surfaces downstream errors with original status codes and error codes.
