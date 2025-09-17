# Building Custom NKN Binary - nkn-norpclimits

This guide walks through building a custom NKN daemon with RPC rate limiting disabled for high-performance blockchain synchronization.

## Prerequisites

- Go 1.19 or later
- Git
- Make
- 4GB+ RAM for compilation
- Linux/Unix environment (Ubuntu/Debian recommended)

## Step-by-Step Build Process

### 1. Install Go Dependencies

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install golang-go git make build-essential

# CentOS/RHEL
sudo yum install golang git make gcc

# Verify Go installation
go version
```

### 2. Clone NKN Source Code

```bash
# Clone official NKN repository
git clone https://github.com/nknorg/nkn.git
cd nkn

# Checkout specific version (recommended for stability)
git checkout v2.2.1
```

### 3. Apply Rate Limiting Modification

Replace the RPC server file with the rate-limit-disabled version:

```bash
# Backup original file
cp api/httpjson/RPCserver.go api/httpjson/RPCserver.go.original

# Copy modified version from this repository
cp ../patches/RPCserver.go api/httpjson/RPCserver.go
```

### 4. Build Modified Binary

```bash
# Clean any previous builds
make clean

# Build the modified nknd binary
make nknd

# Alternative: Build with optimizations
CGO_ENABLED=0 GOOS=linux go build -a -ldflags '-extldflags "-static" -s -w' -o nknd-norpclimits .
```

### 5. Verify Build

```bash
# Check binary exists and is executable
ls -la nknd
./nknd --version

# Quick functionality test
./nknd &
sleep 5
curl -X POST http://127.0.0.1:30003 -d '{"jsonrpc":"2.0","method":"getlatestblockheight","params":{},"id":1}'
killall nknd
```

## Installation

### Replace Existing Binary

```bash
# Stop any running nknd process
sudo pkill nknd
# or
sudo systemctl stop nkn

# Backup original binary
sudo cp /usr/local/bin/nknd /usr/local/bin/nknd.original

# Install modified binary
sudo cp nknd /usr/local/bin/nknd
sudo chmod +x /usr/local/bin/nknd
```

### Install as Alternative Binary

```bash
# Install alongside original (safer option)
sudo cp nknd /usr/local/bin/nknd-norpclimits
sudo chmod +x /usr/local/bin/nknd-norpclimits
```

## Configuration

### NKN Node Configuration

Create or modify `~/.nkn/config.json`:

```json
{
  "RPCRateLimit": 0,
  "RPCIPRateLimit": 0,
  "RPCRateBurst": 0,
  "RPCIPRateBurst": 0,
  "HttpJsonHost": "127.0.0.1",
  "HttpJsonPort": 30003,
  "HttpsJsonHost": "127.0.0.1",
  "HttpsJsonPort": 30004,
  "RPCReadTimeout": 30,
  "RPCWriteTimeout": 30,
  "RPCIdleTimeout": 300,
  "RPCKeepAlivesEnabled": true,
  "DisableWebGUI": true,
  "NAT": false
}
```

### Security Configuration

**Critical: Ensure the node only accepts local connections**

```bash
# Check that nknd binds only to localhost
netstat -an | grep :30003
# Should show: 127.0.0.1:30003 NOT 0.0.0.0:30003

# Add firewall rule to block external access
sudo iptables -A INPUT -p tcp --dport 30003 ! -s 127.0.0.1 -j DROP
```

## Testing the Build

### Rate Limiting Test

```bash
#!/bin/bash
# test-rate-limits.sh

echo "Starting nknd with rate limits disabled..."
nknd-norpclimits &
NKND_PID=$!

sleep 10

echo "Testing rapid requests (should NOT be rate limited)..."
for i in {1..50}; do
  curl -s -X POST http://127.0.0.1:30003 \
    -d '{"jsonrpc":"2.0","method":"getlatestblockheight","params":{},"id":'$i'}' \
    -H "Content-Type: application/json" &
done

wait
echo "All requests completed - rate limiting successfully disabled!"

kill $NKND_PID
```

### Performance Benchmark

```bash
#!/bin/bash
# benchmark-rpc.sh

START_TIME=$(date +%s.%3N)
REQUESTS=100

echo "Sending $REQUESTS concurrent requests..."

for i in $(seq 1 $REQUESTS); do
  curl -s -X POST http://127.0.0.1:30003 \
    -d '{"jsonrpc":"2.0","method":"getlatestblockheight","params":{},"id":'$i'}' &
done

wait

END_TIME=$(date +%s.%3N)
DURATION=$(echo "$END_TIME - $START_TIME" | bc)
RPS=$(echo "scale=2; $REQUESTS / $DURATION" | bc)

echo "Completed $REQUESTS requests in $DURATION seconds"
echo "Rate: $RPS requests/second"

if (( $(echo "$RPS > 50" | bc -l) )); then
  echo "SUCCESS: High RPS achieved - rate limiting disabled"
else
  echo "WARNING: Low RPS - check if rate limiting is still active"
fi
```

## Troubleshooting

### Build Errors

**Go version too old:**
```bash
# Remove old Go
sudo rm -rf /usr/local/go

# Install latest Go
wget https://go.dev/dl/go1.21.5.linux-amd64.tar.gz
sudo tar -C /usr/local -xzf go1.21.5.linux-amd64.tar.gz
export PATH=$PATH:/usr/local/go/bin
```

**Missing dependencies:**
```bash
go mod download
go mod tidy
```

**Build cache issues:**
```bash
go clean -cache
go clean -modcache
make clean
```

### Runtime Issues

**Binary won't start:**
```bash
# Check permissions
chmod +x nknd

# Check for missing libraries
ldd nknd

# Check system resources
free -h
df -h
```

**RPC not responding:**
```bash
# Check if port is bound
ss -tulpn | grep :30003

# Check firewall
sudo iptables -L | grep 30003

# Test connectivity
telnet 127.0.0.1 30003
```

**Rate limiting still active:**
```bash
# Verify you're using the modified binary
which nknd
nknd --version

# Check logs for rate limit messages
tail -f ~/.nkn/log/*.log | grep -i "rate limit"
```

## Security Considerations

### Network Security

**The modified binary removes important security features:**

- Rate limiting prevents DoS attacks
- Removing it makes the node vulnerable to abuse
- Only use on isolated, local networks
- Never expose to public internet

### Monitoring

```bash
# Monitor system resources
htop
iotop

# Monitor network connections
ss -tuln | grep :30003
netstat -an | grep :30003

# Monitor for unusual activity
tail -f ~/.nkn/log/*.log
```

### Backup and Recovery

```bash
# Backup wallet and configuration
tar -czf nkn-backup-$(date +%Y%m%d).tar.gz ~/.nkn/

# Backup blockchain data (if needed)
tar -czf chaindb-backup-$(date +%Y%m%d).tar.gz ~/.nkn/ChainDB/
```

## Cross-Platform Builds

### Linux ARM64
```bash
GOOS=linux GOARCH=arm64 go build -o nknd-linux-arm64 .
```

### macOS
```bash
GOOS=darwin GOARCH=amd64 go build -o nknd-macos .
```

### Windows
```bash
GOOS=windows GOARCH=amd64 go build -o nknd-windows.exe .
```

## Docker Build

```dockerfile
FROM golang:1.21-alpine AS builder

RUN apk add --no-cache git make
WORKDIR /src
COPY . .
RUN make nknd

FROM alpine:latest
RUN apk add --no-cache ca-certificates
COPY --from=builder /src/nknd /usr/local/bin/
EXPOSE 30003
CMD ["nknd"]
```

Build and run:
```bash
docker build -t nkn-norpclimits .
docker run -p 127.0.0.1:30003:30003 nkn-norpclimits
```

## Maintenance

### Updating to Newer NKN Versions

```bash
# Pull latest NKN changes
git fetch origin
git checkout v2.2.2  # or latest version

# Re-apply the rate limiting patch
cp ../patches/RPCserver.go api/httpjson/RPCserver.go

# Rebuild
make clean
make nknd
```

### Performance Monitoring

```bash
# Create monitoring script
cat > monitor-nkn.sh << 'EOF'
#!/bin/bash
while true; do
  echo "=== $(date) ==="
  echo "CPU: $(top -bn1 | grep "Cpu(s)" | awk '{print $2}')"
  echo "Memory: $(free | grep Mem | awk '{printf("%.1f%%", $3/$2 * 100.0)}')"
  echo "Connections: $(ss -tn | grep :30003 | wc -l)"
  echo "Block Height: $(curl -s -X POST http://127.0.0.1:30003 -d '{"jsonrpc":"2.0","method":"getlatestblockheight","params":{},"id":1}' | jq -r '.result')"
  echo "---"
  sleep 30
done
EOF

chmod +x monitor-nkn.sh
./monitor-nkn.sh
```

## Important Notes

1. **This modification removes security features** - only use locally
2. **Test thoroughly** before using in production sync scenarios
3. **Monitor resource usage** - high RPC load can consume significant resources
4. **Keep backups** of original binary and configuration
5. **Update carefully** - newer NKN versions may require patch modifications

## Support

For issues specific to this modification:
- Check that you're using the correct modified binary
- Verify rate limiting is actually disabled with the test scripts
- Monitor system resources during high-load sync operations
- Ensure firewall rules prevent external access
