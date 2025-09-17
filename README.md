# nkn-norpclimits
NKN node modification that removes RPC rate limiting for high-performance blockchain synchronization. Includes optimized NKN OpenAPI sync tools with parallel RPC requests. Increases sync speed from 30 blocks/minute to 8,000+ blocks/minute. For local development and catch-up scenarios only.
# nkn-norpclimits

**NKN node modification that removes RPC rate limiting for high-performance blockchain synchronization. Includes optimized NKN OpenAPI sync tools with parallel RPC requests. Increases sync speed from 30 blocks/minute to 8,000+ blocks/minute. For local development and catch-up scenarios only.**

## Performance Results

- **Before**: 30 blocks/minute, 48+ day sync time, 629k job backlog
- **After**: 8,250 blocks/minute, 15 hour sync time, zero queue backlog
- **Improvement**: 275x faster processing, 99.8% time reduction

## What This Does

This repository provides two key optimizations for NKN blockchain synchronization:

1. **Modified NKN daemon** - Removes RPC rate limiting from `nknd` binary
2. **Optimized NKN OpenAPI sync** - Parallel RPC requests and intelligent batching

## Warning

**This modification removes security features for performance gains:**

- Use only for LOCAL blockchain synchronization
- NOT for production mining nodes
- NOT for public-facing nodes
- Rate limiting exists for security reasons
- Use at your own risk

## Quick Start

### 1. Build Modified NKN Binary

```bash
# Clone NKN source
git clone https://github.com/nknorg/nkn.git
cd nkn
git checkout v2.2.1

# Apply rate limiting patch
cp patches/RPCserver.go api/httpjson/RPCserver.go

# Build
make
```

### 2. Install NKN OpenAPI Components

```bash
# Copy optimized files to your NKN OpenAPI installation
cp sync-tools/NknRpcClient.php app/Services/
cp sync-tools/BatchedSyncBlockchainBlocks.php app/Jobs/
cp sync-tools/InitialSync.php app/Console/Commands/

# Clear caches
php artisan config:clear
composer dump-autoload
```

### 3. Start High-Speed Sync

```bash
# Start modified nknd
./nknd

# Begin optimized sync
php artisan blockchain:init --batch-size=150 --chunk-size=2000 --queue-threshold=50
```

## What Gets Modified

### NKN Daemon Changes
The `api/httpjson/RPCserver.go` file is modified to comment out rate limiting checks:

```go
// DISABLED: Per-IP rate limiting for blockchain sync performance
// if !limiter.Allow() {
//     log.Infof("RPC connection limit of %s reached", host)
//     w.WriteHeader(http.StatusTooManyRequests)
//     return
// }
```

### NKN OpenAPI Changes
- **Parallel RPC requests** instead of sequential
- **Intelligent batch processing** with adaptive sizing
- **Optimized error handling** without rate limit delays
- **Database bulk operations** for faster storage

## Performance Tuning

### Conservative Settings (4GB RAM)
```bash
php artisan blockchain:init --batch-size=100 --chunk-size=1000 --queue-threshold=100
```

### Balanced Settings (8GB RAM)
```bash
php artisan blockchain:init --batch-size=150 --chunk-size=2000 --queue-threshold=50
```

### Aggressive Settings (16GB+ RAM)
```bash
php artisan blockchain:init --batch-size=300 --chunk-size=5000 --queue-threshold=25
```

## Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│  NKN OpenAPI    │    │  Modified nknd   │    │   PostgreSQL    │
│                 │    │                  │    │                 │
│ • Parallel RPC  │───▶│ • No Rate Limits │    │ • Bulk Inserts  │
│ • Smart Batching│    │ • Fast Response  │    │ • Optimized     │
│ • Queue Mgmt    │    │ • Local Only     │    │   Indexing      │
└─────────────────┘    └──────────────────┘    └─────────────────┘
```

## Files Included

- `patches/RPCserver.go` - Modified NKN RPC server without rate limits
- `sync-tools/NknRpcClient.php` - Parallel RPC client for Laravel
- `sync-tools/BatchedSyncBlockchainBlocks.php` - Optimized batch job processor
- `sync-tools/InitialSync.php` - Intelligent chunking sync command
- `docs/build-instructions.md` - Detailed build guide

## Security Considerations

### What This Removes
- Per-IP RPC rate limiting
- Global RPC rate limiting  
- Request throttling mechanisms
- DDoS protection at RPC level

### Security Best Practices
```bash
# Ensure nknd only binds to localhost
netstat -an | grep :30003
# Should show: 127.0.0.1:30003

# Block external access
iptables -A INPUT -p tcp --dport 30003 ! -s 127.0.0.1 -j DROP
```

## Troubleshooting

### Memory Usage Too High
```bash
# Reduce batch sizes
--batch-size=75 --chunk-size=500
```

### Database Lock Errors
```bash
# Reduce concurrent workers in config/horizon.php
'max-processes' => 2
```

### Queue Backing Up
```bash
# Monitor queue health
redis-cli llen blockchainCrawler
php artisan horizon:status
```

## Use Cases

**Perfect for:**
- NKN OpenAPI installations catching up after downtime
- Blockchain explorers needing full sync
- Development environments requiring historical data
- Analytics platforms processing blockchain data

**NOT suitable for:**
- Production mining nodes
- Public RPC endpoints
- Network-exposed infrastructure

## System Requirements

| Performance | CPU | RAM | Storage | Network |
|-------------|-----|-----|---------|---------|
| Conservative | 4 cores | 8GB | SSD | 100Mbps |
| Balanced | 8 cores | 16GB | NVMe | 500Mbps |
| Aggressive | 16+ cores | 32GB+ | NVMe RAID | 1Gbps+ |

## Contributing

1. Fork the repository
2. Test modifications thoroughly
3. Document performance impacts
4. Submit pull request with benchmarks

## License

Apache License 2.0 - see LICENSE file for details.

**Note**: This project modifies the original NKN codebase, which is also licensed under Apache 2.0.

## Acknowledgments

- NKN Team for the original blockchain implementation
- Laravel Team for the framework
- Community contributors who helped optimize and test

## Support

- **Issues**: Report via GitHub Issues
- **Performance**: Include system specs and sync parameters
- **Documentation**: See `/docs` directory for detailed guides

---

**⚠️ Remember: This is for LOCAL synchronization only. Never expose rate-limit-disabled nodes to public networks.**
