# SSL Certificate Setup

The KeyGate requires SSL certificates at:
- `ssl/server.crt` — Certificate file
- `ssl/server.key` — Private key file
- `ssl/chain.pem` — (Optional) CA chain for Let's Encrypt

## Option 1: Self-Signed (Testing Only)

```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout ssl/server.key \
  -out ssl/server.crt \
  -subj "/CN=localhost"
```

## Option 2: Let's Encrypt (Production)

```bash
# Install certbot
sudo apt install certbot

# Generate certificate (requires port 80 open)
sudo certbot certonly --standalone -d your-domain.com

# Copy to ssl/ directory
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem ssl/server.crt
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem ssl/server.key
sudo cp /etc/letsencrypt/live/your-domain.com/chain.pem ssl/chain.pem

# Uncomment SSLCertificateChainFile in ssl/apache-ssl.conf
```

### Auto-Renewal

```bash
# Add to crontab (renews every 60 days)
echo "0 4 1 */2 * certbot renew --quiet && cp /etc/letsencrypt/live/your-domain.com/*.pem /path/to/ssl/ && docker restart oem-activation-web" | sudo crontab -
```

## Option 3: Purchased Certificate

Copy your certificate files:
```bash
cp your-cert.crt ssl/server.crt
cp your-key.key ssl/server.key
cp your-chain.pem ssl/chain.pem  # if provided by CA
```

## Notes

- The `localhost.crt` and `localhost.key` files are development-only and gitignored.
- Never commit real SSL keys to version control.
- After changing certificates, restart the web container: `docker restart oem-activation-web`
