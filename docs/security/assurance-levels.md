# Assurance levels (NIST SP 800-63B-4)

NIST SP 800-63B-4 (2025) defines authenticator assurance levels: AAL1, AAL2 and AAL3. This page says which YorvoID configurations reach which level. It's based on the published text of 800-63B-4, not on summaries of it.

> YorvoID's authentication arrives in milestone M1 (passkeys, recovery codes, sessions, step-up) and M4 (passwords, TOTP, email sign-in links). Until then, this page states the targets that those milestones are tested against.

## The rules that matter most

**Passwords** (section 3.1.1.2): at least 15 characters when the password is the only factor, at least 8 when it's always combined with another factor; at least 64 characters allowed; no composition rules; every new password checked against a blocklist; no periodic changes, but a forced change on evidence of compromise.

**Phishing resistance** (section 2.3.2): at AAL2, at least one phishing-resistant way to authenticate must be offered. Passkeys are.

**Synced passkeys** (appendix B): allowed at AAL2. The server must check the user-verification flag; without user verification, a passkey counts as a single factor. Their backup state shouldn't be grounds for rejection in public-facing applications. Attestation should be used when available, but its absence shouldn't block them. Synced passkeys are **not** allowed at AAL3.

**Sessions** (sections 2.2.3 and 2.3.3):

| Level | Re-authenticate at least every | Idle timeout |
|---|---|---|
| AAL2 | 24 hours (should) | 1 hour (should) |
| AAL3 | 12 hours (shall) | 15 minutes (should) |

## Configurations

| Configuration | Level | Notes |
|---|---|---|
| Password only | AAL1 | Passwords of at least 15 characters |
| Passkey with user verification (synced or device-bound) | AAL2 | Phishing-resistant |
| Password + TOTP | AAL2 | Not phishing-resistant: AAL2 also requires a phishing-resistant option to be offered |
| Device-bound passkey with user verification, attestation per policy | AAL3 | Requires the AAL3 session limits above |
| Recovery code | AAL1 | One factor, can be phished; for getting back in, then adding a passkey |

YorvoID's session presets target AAL2 (`aal2`, `console`) and AAL3 (`aal3`). Passkeys, the phishing-resistant method, are always offered. `yorvoid:doctor` warns when a configuration falls short of its preset's level: passkeys without required user verification, sessions longer than the level allows, or AAL3 while synced passkeys are accepted ([checking the setup](../concepts/symfony.md#checking-the-setup)).
