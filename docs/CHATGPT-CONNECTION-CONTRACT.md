# Native ↔ Cloud compatibility contract

The central MCP service is a separately deployed, separately maintained service.

Native maintains stable installation identities, administrator consent, scoped pairing credentials, revocation and safe default permissions. Existing connections must not reset during ordinary upgrades.

Client tools must not receive server-persisted site credentials. Native must reject unauthorized requests and respect owner/site grants. Human approval is mandatory for production writes.

Do not copy server implementation, encryption internals, OAuth token signing configuration, quota ledger or hosted client registry into public Native.

Cloud-side tests and production deployment remain private. Consumer-facing API changes require explicit compatibility review and isolated two-site staging tests.
