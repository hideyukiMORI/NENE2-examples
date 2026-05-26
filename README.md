# NENE2 Examples

Reference implementations built with [hideyukimori/nene2](https://packagist.org/packages/hideyukimori/nene2) as a Composer dependency.

Each directory is a **self-contained field-trial application** — a full JSON API implementation covering one pattern from the [NENE2 howto library](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/README.md).

> **Field trials** are AI-autonomously built apps used to validate NENE2's API ergonomics, discover friction points, and prove security patterns before they are documented in the howto guides.

---

## How to use

Each example has:

```
<name>/
  src/           Application source code
  tests/         PHPUnit test suite
  database/      schema.sql (and migrations where applicable)
  composer.json  Dependencies — main dep is hideyukimori/nene2
  phpunit.xml    Test configuration
  phpstan.neon   Static analysis configuration
```

To run an example locally:

```bash
cd <name>
composer install
vendor/bin/phpunit
```

---

## Index

| Directory | Pattern | Howto guide |
|---|---|---|
| [ablog](./ablog/) | A/B Testing | [ab-testing.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/ab-testing.md) |
| [agglog](./agglog/) | Admin Report Aggregation | [admin-report-aggregation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/admin-report-aggregation.md) |
| [apikeylog](./apikeylog/) | API Key Management | [api-key-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/api-key-management.md) |
| [auditlog](./auditlog/) | Audit Trail | [audit-trail.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/audit-trail.md) |
| [bookmarklog](./bookmarklog/) | Bookmark System | [bookmark-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/bookmark-system.md) |
| [cachelog](./cachelog/) | Application Caching | [application-caching.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/application-caching.md) |
| [cartlog](./cartlog/) | Shopping Cart | [shopping-cart.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/shopping-cart.md) |
| [circuitlog](./circuitlog/) | Circuit Breaker | [circuit-breaker.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/circuit-breaker.md) |
| [collectionlog](./collectionlog/) | Content Collection | [content-collection.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-collection.md) |
| [commentlog](./commentlog/) | Threaded Comments | [threaded-comments.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/threaded-comments.md) |
| [contentvlog](./contentvlog/) | Content Versioning | [content-versioning.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-versioning.md) |
| [couponlog](./couponlog/) | Coupon / Promo Codes | [coupon-promo-code.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/coupon-promo-code.md) |
| [csrflog](./csrflog/) | CSRF & Idempotency | [csrf-and-json-api.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/csrf-and-json-api.md) |
| [deduplog](./deduplog/) | Request Deduplication | [request-deduplication.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/request-deduplication.md) |
| [distlocklog](./distlocklog/) | Distributed Locking | [distributed-locking.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/distributed-locking.md) |
| [emojilog](./emojilog/) | Emoji Reactions | [emoji-reaction-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/emoji-reaction-system.md) |
| [etaglog](./etaglog/) | ETag & Conditional Requests | [etag-conditional-requests.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/etag-conditional-requests.md) |
| [eventsourcelog](./eventsourcelog/) | Event Sourcing | [event-sourcing.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/event-sourcing.md) |
| [exportlog](./exportlog/) | Personal Data Export | [personal-data-export.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/personal-data-export.md) |
| [featureflaglog](./featureflaglog/) | Feature Flags | [feature-flags.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/feature-flags.md) |
| [feedlog](./feedlog/) | Activity Feed | [activity-feed.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/activity-feed.md) |
| [filelog](./filelog/) | File Metadata Sharing | [file-metadata-sharing.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/file-metadata-sharing.md) |
| [followlog](./followlog/) | User Follow System | [user-follow-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/user-follow-system.md) |
| [geoloclog](./geoloclog/) | Geolocation | [geolocation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/geolocation.md) |
| [grouplog](./grouplog/) | Group Membership | [group-membership-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/group-membership-management.md) |
| [hierarchylog](./hierarchylog/) | Hierarchical Data (Materialized Path) | [hierarchical-data.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/hierarchical-data.md) |
| [hmaclog](./hmaclog/) | Webhook Signature Verification | [webhook-signature.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/webhook-signature.md) |
| [importlog](./importlog/) | CSV Bulk Import | [csv-bulk-import.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/csv-bulk-import.md) |
| [inboundlog](./inboundlog/) | Inbound Webhook Receiver | [inbound-webhook-receiver.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/inbound-webhook-receiver.md) |
| [injectionlog](./injectionlog/) | SQL Injection Prevention | [sql-injection.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/sql-injection.md) |
| [invitelog](./invitelog/) | User Invitation | [user-invitation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/user-invitation.md) |
| [jwtlog](./jwtlog/) | JWT Authentication | [jwt-authentication.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/jwt-authentication.md) |
| [lockoutlog](./lockoutlog/) | Account Lockout | [account-lockout.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/account-lockout.md) |
| [magiclog](./magiclog/) | Passwordless Auth (Magic Link) | [passwordless-auth-magic-link.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/passwordless-auth-magic-link.md) |
| [masklog](./masklog/) | Data Masking | [data-masking.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/data-masking.md) |
| [masslog](./masslog/) | Mass Assignment Defense | [mass-assignment.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/mass-assignment.md) |
| [messagelog](./messagelog/) | Direct Messaging | [direct-messaging-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/direct-messaging-system.md) |
| [nestedlog](./nestedlog/) | Nested JSON Validation | [nested-json-validation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/nested-json-validation.md) |
| [notificationlog](./notificationlog/) | Notification Inbox | [notification-inbox.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/notification-inbox.md) |
| [oauthlog](./oauthlog/) | OAuth2 Social Login | [oauth2-social-login.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/oauth2-social-login.md) |
| [optlocklog](./optlocklog/) | Optimistic Locking | [optimistic-locking.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/optimistic-locking.md) |
| [orderlog](./orderlog/) | Guest Order System | [guest-order-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/guest-order-system.md) |
| [otplog](./otplog/) | OTP Authentication | [otp-authentication.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/otp-authentication.md) |
| [pagelog](./pagelog/) | Pagination | [pagination.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/pagination.md) |
| [paymentlog](./paymentlog/) | Payment Webhook | [payment-webhook.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/payment-webhook.md) |
| [pinlog](./pinlog/) | Content Pinning | [content-pinning.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-pinning.md) |
| [planlog](./planlog/) | Subscription Plan Management | [subscription-plan-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/subscription-plan-management.md) |
| [pointlog](./pointlog/) | Point / Loyalty System | [point-loyalty-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/point-loyalty-system.md) |
| [preflog](./preflog/) | User Preferences | [user-preferences-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/user-preferences-management.md) |
| [profilelog](./profilelog/) | User Profile Management | [user-profile-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/user-profile-management.md) |
| [pubschedulelog](./pubschedulelog/) | Content Scheduling (Time-Based Publish) | [content-scheduling.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-scheduling.md) |
| [pwdlog](./pwdlog/) | Password Hashing | [password-hashing.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/password-hashing.md) |
| [queuelog](./queuelog/) | Job Queue | [job-queue.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/job-queue.md) |
| [ranklog](./ranklog/) | Leaderboard Ranking | [leaderboard-ranking-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/leaderboard-ranking-system.md) |
| [rbaclog](./rbaclog/) | RBAC | [rbac.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/rbac.md) |
| [refreshlog](./refreshlog/) | JWT Refresh Token Rotation | [refresh-token-rotation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/refresh-token-rotation.md) |
| [reportlog](./reportlog/) | Content Moderation | [content-report-moderation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-report-moderation.md) |
| [relatedlog](./relatedlog/) | Content Relations (Typed M:N Links) | [content-relations.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/content-relations.md) |
| [resetlog](./resetlog/) | Password Reset | [password-reset.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/password-reset.md) |
| [reviewlog](./reviewlog/) | Product Reviews | [product-review-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/product-review-system.md) |
| [salelog](./salelog/) | Flash Sale | [flash-sale-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/flash-sale-system.md) |
| [searchlog](./searchlog/) | Search & Autocomplete | [search-autocomplete.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/search-autocomplete.md) |
| [signedlog](./signedlog/) | Signed URLs | [signed-urls.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/signed-urls.md) |
| [sluglog](./sluglog/) | Slug Management | [slug-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/slug-management.md) |
| [meterlog](./meterlog/) | API Usage Metering | [api-usage-metering.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/api-usage-metering.md) |
| [softdeletelog](./softdeletelog/) | Soft Delete | [soft-delete.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/soft-delete.md) |
| [stepflowlog](./stepflowlog/) | Multi-step Workflow | [multi-step-workflow.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multi-step-workflow.md) |
| [taglog](./taglog/) | Tagging System (M:N) | [tagging-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/tagging-system.md) |
| [tenantlog](./tenantlog/) | Multi-tenant Isolation | [multi-tenant-isolation.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/multi-tenant-isolation.md) |
| [throttlelog](./throttlelog/) | Rate Limiting | [rate-limiting.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/rate-limiting.md) |
| [tokenlog](./tokenlog/) | Access Token Management | [access-token-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/access-token-management.md) |
| [totplog](./totplog/) | TOTP Two-Factor Auth | [totp-authentication.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/totp-authentication.md) |
| [txlog](./txlog/) | Database Transactions | [transactions.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/transactions.md) |
| [uploadlog](./uploadlog/) | File Upload | [file-upload.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/file-upload.md) |
| [versionlog](./versionlog/) | API Versioning | [api-versioning.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/api-versioning.md) |
| [votelog](./votelog/) | Voting System | [voting-system.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/voting-system.md) |
| [webhookdeliverylog](./webhookdeliverylog/) | Webhook Delivery (outbound) | [webhook-delivery.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/webhook-delivery.md) |
| [wishlistlog](./wishlistlog/) | Wishlist Management | [wishlist-management.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/wishlist-management.md) |

---

## Related

- **Framework**: [hideyukimori/nene2](https://packagist.org/packages/hideyukimori/nene2) on Packagist
- **Source**: [hideyukiMORI/NENE2](https://github.com/hideyukiMORI/NENE2) on GitHub
- **Howto guides**: [docs/howto/README.md](https://github.com/hideyukiMORI/NENE2/blob/main/docs/howto/README.md) — 100 task-focused guides
- **llms.txt**: [llms.txt](https://github.com/hideyukiMORI/NENE2/blob/main/llms.txt) — AI-readable index
