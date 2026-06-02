# User-Data Validation System (`ValidationBase` + central endpoint)

`payment-base` provides a **provider-agnostic, character-level validation
framework** for user/address input on the checkout boundary. Each PSP module
ships only its own *rules file* and a *message formatter*; the engine, the
hardened HTTP endpoint, the rule grammar, and the security guards all live here
and are shared by every PSP.

This document explains the architecture and **how another payment module adds
fields or adopts the framework**.

---

## 1. Components (what payment-base owns)

```
src/Validation/
├── ValidationBase.php / ValidationBaseInterface.php   # engine: validateField()
├── CharacterClass.php                                 # universal blocklist + class tokens
├── RuleSet.php                                         # parsed allow/block tokens for one field
├── FieldValidationResult.php                           # VO: {valid, code, offendingChar}
├── ValidationRuleLoaderInterface.php
├── FilesystemValidationRuleLoader.php                  # <pluginRoot>/src/Resources/validation-rules.php
├── PluginPathResolverInterface.php / OxidPluginPathResolver.php
├── TaggedServiceCollection.php                         # gettable wrapper for tagged iterators
├── Guard/                                              # 7 request guards (see §5)
├── RateLimit/                                          # per-PSP rate-limit override SPI
└── Message/MessageFormatterInterface.php               # per-PSP message SPI
src/Controller/ValidationApiController.php              # cl=oepaymentvalidationapi&fnc=validate
```

A PSP module (e.g. `stripe`, `paypal`) contributes only:

```
<plugin>/src/Resources/validation-rules.php            # the field rules (required)
<plugin>/…/SomeMessageFormatter.php                    # tagged MessageFormatter (optional, for messages)
<plugin>/…/SomeRateLimitOverride.php                   # tagged rate-limit override (optional)
+ frontend wiring that POSTs to the central endpoint, or a server-side call to ValidationBase
```

---

## 2. The rule grammar

Each field declares an `allow` list and an optional `block` list, both
**space-separated** strings of two kinds of tokens:

| Token kind | Examples | Meaning |
|---|---|---|
| **Class token** (all-uppercase) | `UNICODE_LETTERS`, `LETTERS`, `NUMBERS`, `SPACES` | a character category |
| **Literal char** | `'  -  .  /  #  &  +  (  )` | exactly that one character |

Built-in class tokens (`CharacterClass::matchesClass()`):

| Token | Matches |
|---|---|
| `UNICODE_LETTERS` | any Unicode letter, `\p{L}` (e.g. `ä`, `ñ`, `字`) |
| `LETTERS` | ASCII letters only, `[A-Za-z]` |
| `NUMBERS` | any Unicode digit, `\p{N}` |
| `SPACES` | the regular space `U+0020` **only** (not tabs/newlines) |

Semantics (`ValidationBase::validateField($field, $value)`):

1. **Universal blocklist first** (`CharacterClass::hasUniversalReject`) — always
   rejects control chars `U+0000–001F`, `U+007F`, `U+0080–009F`, and zero-width /
   invisible code points (`U+200B/C/D`, `U+FEFF`, `U+00AD`, `U+2060`). Cannot be
   overridden by a plugin → code `control_character`.
2. **`block`** — any character in the block list fails → code `blocked_character`.
3. **`allow`** — every remaining character must match *some* allow token, else
   fails → code `disallowed_character`.
4. **Empty / null value** → `valid` (the non-empty/required check is OXID's job,
   not this validator's).
5. **Unknown field name** (no rule entry) → `valid` by contract — a plugin only
   constrains the fields it declares; posting extra fields is harmless.

Codes are the constants on `FieldValidationResult` (`CODE_DISALLOWED_CHARACTER`,
`CODE_BLOCKED_CHARACTER`, `CODE_CONTROL_CHARACTER`).

---

## 3. The per-plugin rules file

**Location (convention, resolved by `FilesystemValidationRuleLoader`):**

```
<pluginRoot>/src/Resources/validation-rules.php
```

`<pluginRoot>` is resolved from the module id via `OxidPluginPathResolver`:
`shopRootPath . '/' . moduleConfiguration->getModuleSource()`. So the file is
found purely from the module id — no registration needed.

**Shape:**

```php
<?php
declare(strict_types=1);

return [
    'fields' => [
        [
            'field' => 'firstName',                       // logical field name
            'rules' => [
                'allow' => "UNICODE_LETTERS SPACES ' - .",
                'block' => ': ; < > { } ( ) | \\ / ~ ! @ # $ % ^ * = + " ? , & _',
            ],
        ],
        [
            'field' => 'phone',
            'rules' => [
                'allow' => 'NUMBERS SPACES + - ( )',      // block is optional
            ],
        ],
        // ...
    ],
];
```

> The space character is the token *separator*; to allow a literal space use the
> `SPACES` class token, never a literal `" "`.

---

## 4. How payment-base picks the right rules

The plugin id is a **request parameter**, so one endpoint serves every PSP:

```
POST … pluginModuleId=oe_payments_stripe_wallet
        │
        ▼
ValidationApiController::runValidation()
   $validator = new ValidationBase($pluginModuleId, $loader);   // bound to that plugin
        │
        ▼
ValidationBase → loader->loadFor($pluginModuleId)
        │
        ▼
FilesystemValidationRuleLoader → OxidPluginPathResolver::resolvePath($pluginModuleId)
        │
        ▼
<that module's source>/src/Resources/validation-rules.php
```

The message formatter is matched the same way: the controller picks the tagged
`MessageFormatterInterface` whose `getPluginModuleId()` equals the requested id.

---

## 5. The central endpoint

**URL:** `index.php?cl=oepaymentvalidationapi&fnc=validate` (controller key
`oepaymentvalidationapi`, registered in `payment-base/metadata.php`).

**Request (POST, form-encoded or JSON):**

| Param | Meaning |
|---|---|
| `pluginModuleId` | the requesting module id (selects the rules + formatter) |
| `stoken` | OXID session challenge token (CSRF) |
| *any other key* | a field to validate, keyed by **logical field name** (`firstName`, `street`, …). Everything except the two meta keys above is treated as a field. |

**Success response (HTTP 200, `application/json`):**

```json
{ "valid": false, "errors": [
  { "field": "firstName", "code": "blocked_character", "char": ":", "message": "…" }
] }
```

`message` is `null` when no formatter is registered for the plugin id.

**Guard chain** (priority order; first failure → HTTP 4xx with an **empty body**,
so scanners get no fingerprint):

| Prio | Guard | Rejects |
|---|---|---|
| 10 | `PostOnlyGuard` | non-POST (405) |
| 20 | `PayloadSizeGuard` | body > 4 KiB or > 32 fields (413) |
| 30 | `ActiveSessionGuard` | no active OXID session (401) |
| 40 | `SameOriginGuard` | `Origin`/`Referer` ≠ shop URL (403) |
| 50 | `CsrfTokenGuard` | bad/missing `stoken` (403) |
| 60 | `RateLimitGuard` | over the per-`(pluginId, session)` limit (429) |
| 70 | `PluginIdAllowlistGuard` | `pluginModuleId` is not an **active** module (422) |

Guards are tagged `oe.payment_base.validation_guard` (collected via the
`oe.payment_base.validation_guard_iterator` service). Add a guard = add a tagged
service; no controller edit (OCP).

---

## 6. How to extend

### 6.1 Add a new field to an existing plugin

Pure plugin change — **no payment-base edit**:

1. Add a `{field, rules}` entry to that plugin's `src/Resources/validation-rules.php`
   using the existing class tokens + literals (§2).
2. Make sure the field reaches validation:
   - **Frontend/OPC:** the plugin's widget must POST the value under the logical
     field name to the endpoint.
   - **Server-side checkout:** the plugin's own validator/field-reader must read
     the new column and feed it to `ValidationBase::validateField()`.
3. Add a translated message for it in the plugin's `MessageFormatter` (e.g. a
   `…_LABEL_<FIELD>` key) so the error reads nicely.

That's it — the engine validates any declared field automatically.

### 6.2 Adopt the framework in a NEW payment module

Zero new endpoints, zero new guards, no payment-base change:

1. **Ship `<plugin>/src/Resources/validation-rules.php`** (§3) with your fields.
2. **Ship a `MessageFormatter`** implementing
   `OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface`:
   ```php
   final class MyPspMessageFormatter implements MessageFormatterInterface
   {
       public function getPluginModuleId(): string { return 'my_psp_module_id'; }
       public function format(string $field, string $code, ?string $offendingChar): string
       {
           // return a translated, user-friendly message for ($field, $code)
       }
   }
   ```
   Register it in your `services.yaml` tagged
   `oe.payment_base.validation_message_formatter`. The controller routes by
   `getPluginModuleId()`. (Optional — without it, `message` is `null` and the
   client falls back to a generic text.)
3. **Wire the frontend / server-side call:**
   - **AJAX (one-page-checkout style):** POST the live form fields +
     `pluginModuleId` + `stoken` to `cl=oepaymentvalidationapi&fnc=validate`, then
     render `errors[]`. (See the Stripe widget for a reference Stimulus
     implementation that emits `oe:payment:error` so OPC's own notice system
     shows the messages.)
   - **Classic checkout (server-side):** in your order controller, before
     dispatching the payment, build a `ValidationBase($yourModuleId, $loader)` (or
     a thin façade over it), validate the stored user fields, and short-circuit on
     failure. No HTTP round-trip needed.

### 6.3 Per-PSP rate-limit override (optional)

Default is 30 requests / minute per `(pluginId, session)`. To change it for your
module, register a service tagged `oe.payment_base.rate_limit_override`
implementing `RateLimitOverrideInterface`
(`getPluginModuleId(): string`, `getLimitPerMinute(): int`).

### 6.4 When you DO need to touch payment-base

Only for framework-level additions, e.g.:

- **A new character class token** (something `UNICODE_LETTERS` / `LETTERS` /
  `NUMBERS` / `SPACES` can't express) → add a case to
  `CharacterClass::matchesClass()` and document it here. This is shared, so it
  must be additive and backwards-compatible.
- **A new guard** → add an SRP class implementing `ValidationGuardInterface`,
  tagged `oe.payment_base.validation_guard` with a `priority`.

Adding fields with the existing grammar never requires this.

---

## 7. Reference: data flow at a glance

```
[browser/OPC widget]                         [classic checkout controller]
   POST fields + pluginModuleId + stoken         build ValidationBase(moduleId, loader)
            │                                              │
            ▼                                              ▼
   ValidationApiController (7 guards) ───────────► ValidationBase::validateField()
            │                                              │
            ▼                                              ▼
   FilesystemValidationRuleLoader ──► <plugin>/src/Resources/validation-rules.php
            │
            ▼
   MessageFormatter (matched by pluginModuleId) ──► errors[].message
            │
            ▼
   JSON { valid, errors[] }  /  HTTP 4xx (empty) on guard failure
```