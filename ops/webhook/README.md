# VPS Webhook Receiver (without GitHub Actions)

Minimal flow:

1. GitHub `push` webhook -> VPS endpoint
2. Endpoint verifies `X-Hub-Signature-256`
3. Runs `scripts/ci-run.sh`
4. If CI green -> `scripts/deploy-vps.sh`

Security requirements:
- Restrict endpoint by firewall allowlist (GitHub IP ranges)
- Verify HMAC signature
- Keep webhook secret outside git
- Use deploy lock to avoid concurrent deploys
