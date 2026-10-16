# Security policy

Do not put API keys, license keys, production database exports or private website content in public issues.

For a suspected vulnerability, use the repository's private vulnerability reporting feature if it is enabled; otherwise contact the repository owner privately. Include the affected version, minimal reproduction and impact without exposing real credentials.

This preview is not a substitute for a production security audit. API keys may be configured using `CTP_ANTHROPIC_API_KEY` outside the database. Do not commit `wp-config.php`, `.env` or premium plugin archives.

Only website text needed for a requested translation and configured terminology are sent to Anthropic. The scanner is restricted to same-origin public URLs and does not forward authenticated cookies. For a full behavior/coverage description, read the README.
